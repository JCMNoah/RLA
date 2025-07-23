/* *********************************************************************
 * This function powers a dynamic form shortcode [section_form] in WordPress for displaying and saving lesson section answers per logged-in user. It loads metadata for a given section, retrieves existing user responses, renders a JSON-based dynamic form, and handles saving answers. Once all fields are filled, the section is marked as "completed" and a "Generate PDF" link appears.
 * **********************************************************************/


add_shortcode('section_form', 'render_section_form');

function render_section_form() {
	if (!is_user_logged_in()) return '<p>Please log in to view this section.</p>';
	if (!isset($_GET['sid'])) return '<p>No section specified.</p>';
	
	$generated_mode = isset($_GET['generated']) && $_GET['generated'] == 1;

	$section_id = intval($_GET['sid']);
	$section = get_post($section_id);
	if (!$section || $section->post_type !== 'sections') return '<p>Invalid section.</p>';

	$user_id = get_current_user_id();
	$data = get_section_data($section_id);
	if (!$data) return '<p>Invalid or missing form data.</p>';

	list($user_answers, $existing_post_id) = get_existing_answers($user_id, $section_id);

	$success = false;
	$error_message = '';
	$score_message = '';
	$explanations = [];

	if (is_form_submitted()) {
		$answers = sanitize_user_answers($_POST['answers']);
		list($error_message, $success, $score_message, $explanations, $user_answers) = 
			handle_form_submission($data, $answers, $section_id, $user_id, $existing_post_id);
	}

	return render_form_output($data, $section_id, $user_id, $success, $error_message, $score_message, $explanations, $user_answers, $existing_post_id, $generated_mode);
}

function get_section_data($section_id) {
	$section_data = get_post_meta($section_id, 'form_json', true);
	return json_decode($section_data, true);
}

function get_existing_answers($user_id, $section_id) {
	$existing_answers = get_posts([
		'post_type' => 'lesson_answer',
		'author' => $user_id,
		'meta_query' => [['key' => 's_id', 'value' => $section_id]],
		'numberposts' => 1
	]);

	$user_answers = [];
	$existing_post_id = 0;

	if (!empty($existing_answers)) {
		$existing_post_id = $existing_answers[0]->ID;
		$json = get_post_meta($existing_post_id, 'answers_json', true);
		$user_answers = json_decode($json, true) ?: [];
	}

	return [$user_answers, $existing_post_id];
}

function is_form_submitted() {
	return $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answers']) && isset($_POST['section_id']);
}

function sanitize_user_answers($answers) {
	return array_map('sanitize_text_field', $answers);
}


function handle_form_submission($data, $answers, $section_id, $user_id, $existing_post_id) {
	$section_type = get_post_meta($section_id, 'section_type', true);
	$error_message = '';
	$success = false;
	$score_message = '';
	$explanations = [];

	$user_answers = $answers;

	if ($section_type === 'quiz') {
		// Only check for unanswered fields in quizzes
		$unanswered = [];
		foreach ($data['items'] as $item) {
			foreach ($item['fields'] as $field) {
				if (!isset($answers[$field['id']]) || $answers[$field['id']] === '') {
					$unanswered[] = $field['id'];
				}
			}
		}

		if (count($unanswered)) {
			$error_message = "Please answer all questions. " . count($unanswered) . " unanswered.";
			return [$error_message, $success, $score_message, $explanations, $user_answers];
		}

		list($score_message, $explanations) = handle_quiz_scoring($data, $answers, $section_id, $user_id);
	} else {
		// For section types, check if all fields are filled
		$is_complete = true;
		foreach ($data['items'] as $item) {
			foreach ($item['fields'] as $field) {
				if (!isset($answers[$field['id']]) || $answers[$field['id']] === '') {
					$is_complete = false;
					break 2; // break both loops
				}
			}
		}
		save_answers($answers, $user_id, $section_id, $existing_post_id, $is_complete);
	}

	$success = true;
	return [$error_message, $success, $score_message, $explanations, $user_answers];
}





function handle_quiz_scoring($data, $answers, $section_id, $user_id) {
	$total = 0;
	$correct = 0;
	$explanations = [];

	foreach ($data['items'] as $item) {
		foreach ($item['fields'] as $field) {
			$field_id = $field['id'];
			$question = $item['title'] ?? '';
			$correct_index = $field['answer'] ?? null;
			$options = $field['options'] ?? [];
			$user_answer = $answers[$field_id] ?? '';

			if ($correct_index !== null && isset($options[$correct_index])) {
				$total++;
				$correct_answer = $options[$correct_index];
				if ($user_answer === $correct_answer) {
					$correct++;
				} else {
					$explanations[] = '<div style="margin-bottom: 16px;"><p><strong>' . esc_html($question) . '</strong><br><strong>Correct answer:</strong> ' . esc_html($correct_answer) . '</p><p>' . esc_html($field['explanation'] ?? 'No explanation provided.') . '</p></div>';
				}
			}
		}
	}

	$score_percent = ($total > 0) ? round(($correct / $total) * 100) : 0;
	$score_correct = "You scored <strong>$correct/$total</strong> correct.";
	$score_message = get_rating_feedback($section_id, $score_percent, $score_correct);

	// ✅ Generate PDF HTML from explanations and message
	$template_html = '';
	$template_html .= '<div class="notice success">' . $score_message . '</div>';
	
	$template_html .= build_explanations_html($explanations);


	$safe_html = convert_to_tcpdf_html($template_html);
	$pdf_link = render_section_pdf([], $section_id, $safe_html, $user_id);

	// ✅ Now pass the link into save_quiz_score
	save_quiz_score($user_id, $section_id, $correct, $total, $pdf_link);

	return [$score_message, $explanations];
}



function save_answers($answers, $user_id, $section_id, $existing_post_id, $is_complete = true) {
	$answers_json = json_encode($answers);
	if ($existing_post_id) {
		wp_update_post(['ID' => $existing_post_id, 'post_status' => 'publish']);
		update_post_meta($existing_post_id, 'answers_json', $answers_json);
		update_post_meta($existing_post_id, 'is_completed', $is_complete ? 1 : 0);
	} else {
		$answer_post_id = wp_insert_post([
			'post_type' => 'lesson_answer',
			'post_title' => 'Answer - User ' . $user_id . ' - Section ' . $section_id,
			'post_status' => 'publish',
			'post_author' => $user_id
		]);
		update_post_meta($answer_post_id, 's_id', $section_id);
		update_post_meta($answer_post_id, 'answers_json', $answers_json);
		update_post_meta($answer_post_id, 'userid', $user_id);
		update_post_meta($answer_post_id, 'is_completed', $is_complete ? 1 : 0);
	}
}



function render_form_output($data, $section_id, $user_id, $success, $error_message, $score_message, $explanations, $user_answers, $existing_post_id, $generated_mode=false) {
	$lesson_id = get_post_meta($section_id, 'lesson_id', true);
	$module_id = $lesson_id ? get_post_meta($lesson_id, 'module_id', true) : null;
	$lesson_order = $lesson_id ? get_post_meta($lesson_id, 'lesson_order', true) : null;
	$module_number = $module_id ? get_post_meta($module_id, 'module_number', true) : null;
	$section_type = get_post_meta($section_id, 'section_type', true);

	ob_start();
	echo '<div class="side-padding form-page">';
	
	
	if ($generated_mode && $existing_post_id) {
		$html_content = get_post_meta($existing_post_id, 'pdf_html', true);
		$pdf_url = get_post_meta($existing_post_id, 'pdf_link', true);

		if ($html_content) {
			echo '<div class="notice success">Your responses have been saved as a PDF. You can download it below.</div>';
			echo '<div style="margin-bottom:20px;"><a href="' . esc_url($pdf_url) . '" class="button" target="_blank">Download PDF</a></div>';
			echo '<div class="pdf-html-content">' . wp_kses_post($html_content) . '</div>';
			echo '</div>'; // close .side-padding
			return ob_get_clean();
		}
	}
	
	
	echo '<p>Module ' . esc_html($module_number) . ' Lesson ' . esc_html($lesson_order) . '</p>';
	if ($success && $section_type !== 'quiz') echo '<div class="notice success">Your answers have been saved.</div>';
	if ($error_message) echo '<div class="notice error">' . esc_html($error_message) . '</div>';

	$template_html = '';
	if ($section_type === 'quiz' && $success) {
		
		// 🔍 Get the latest score with PDF link
		$args = [
			'post_type'   => 'lesson_answer',
			'post_status' => 'publish',
			'author'      => $user_id,
			'meta_query'  => [
				[
					'key'     => 's_id',
					'value'   => $section_id,
					'compare' => '='
				]
			],
			'numberposts' => 1
		];

		$answers = get_posts($args);
		$pdf_link = '';

		if (!empty($answers)) {
			$scores_json = get_post_meta($answers[0]->ID, 'scores', true);
			$scores = json_decode($scores_json, true);

			if (is_array($scores) && !empty($scores)) {
				$last_entry = end($scores);
				if (!empty($last_entry['pdf'])) {
					$pdf_link = esc_url($last_entry['pdf']);
				}
			}
		}

		if (!empty($pdf_link)) {
			echo '<div class="notice success" style="margin-top:20px;">';
			echo '<strong>Your review has been saved as a PDF:</strong><br>';
			echo '<a href="' . $pdf_link . '" target="_blank">Download Your Quiz Review PDF</a>';
			echo '</div>';
		}

		
		
		
		//if ($score_message) {
			$template_html .= '<div class="notice success">' . $score_message . '</div>';
		//}

		echo $template_html;
		
		// Just display the HTML (no PDF generation here)
		echo build_explanations_html($explanations);
		
		

	}

	// ✅ Only show form if not already submitted or PDF generated
	$has_generated = isset($_GET['generated']) && $_GET['generated'] == '1';
	if (!$success && !$has_generated) {
		render_form_fields($data, $section_id, $section_type, $user_answers);
	}


	// Show Generate PDF button for completed non-quiz sections
	if ($section_type !== 'quiz' && !empty($existing_post_id) && get_post_meta($existing_post_id, 'is_completed', true)) {
		if (isset($_GET['generated']) && $_GET['generated'] == '1') {
			// Fetch the latest PDF from scores
			$scores_json = get_post_meta($existing_post_id, 'scores', true);
			$scores = json_decode($scores_json, true);

			if (is_array($scores) && !empty($scores)) {
				$last_score = end($scores);
				if (!empty($last_score['pdf'])) {
					echo '<div class="notice success" style="margin-top:20px;">';
					echo '<strong>Your PDF has been generated:</strong><br>';
					echo '<a href="' . esc_url($last_score['pdf']) . '" target="_blank">Download PDF</a>';
					echo '</div>';
				}
			}
		} else {
			// Show the button to generate
			$pdf_url = home_url('/generate-pdf/?sid=' . $section_id . '&uid=' . $user_id);
			echo '<div style="margin-top:20px; text-align:center;">';
			echo '<button class="submit-btn-style" id="generate-pdf-btn" data-section-id="' . $section_id . '">Generate PDF</button>';
			echo '</div>';
		}
	}


	echo '</div>';
	return ob_get_clean();
}



function build_explanations_html($explanations) {
	if (empty($explanations)) return '';

	$html = '<hr /><div><strong>Here are the items you need to review and study:</strong><ul>';
	foreach ($explanations as $exp) {
		$html .= '<li>' . wp_kses_post( html_entity_decode( $exp ) ) . '</li>';
	}
	$html .= '</ul></div>';

	return $html;
}










function render_form_fields($data, $section_id, $section_type, $user_answers) {
    echo '<form method="post" class="section-form" id="section-form">';
    echo '<input type="hidden" name="section_id" value="' . esc_attr($section_id) . '">';
    
    if (!empty($data['section_title'])) echo '<h2>' . esc_html($data['section_title']) . '</h2>';
    if (!empty($data['instructions'])) echo '<p><em>' . esc_html($data['instructions']) . '</em></p>';

    foreach ($data['items'] as $item) {
        echo '<div class="form-group">';
        if (!empty($item['title'])) echo '<p class="question-header">' . esc_html($item['title']) . '</p>';
        foreach ($item['fields'] as $field) render_field_input($field, $user_answers, $section_type);
        echo '</div>';
    }

    echo '<div class="form-actions" style="text-align:right; position:relative;">';
    echo '<button type="submit" class="submit-btn">' . ($section_type === 'quiz' ? 'Score Quiz' : 'Save Answers') . '</button>';
    echo '<span class="save-status" style="display:none; margin-left:10px; font-weight:bold;"></span>';
    echo '</div>';
    echo '</form>';
}



function render_field_input($field, $user_answers, $section_type) {
	$field_id = $field['id'];
	$value = $user_answers[$field_id] ?? '';
	$required = ($section_type === 'quiz') ? 'required' : '';

	echo '<div class="field">';
	if (!empty($field['label'])) {
		echo '<label for="' . esc_attr($field_id) . '">' . esc_html($field['label']) . '</label><br>';
	}

	if ($field['type'] === 'textarea') {
		$maxlength = isset($field['maxlength']) ? intval($field['maxlength']) : 1000;
		echo '<textarea id="' . esc_attr($field_id) . '" name="answers[' . esc_attr($field_id) . ']" ' . $required . ' maxlength="' . $maxlength . '">' . esc_textarea($value) . '</textarea>';
	} elseif ($field['type'] === 'radio' && !empty($field['options'])) {
		foreach ($field['options'] as $option) {
			$checked = ($option == $value) ? 'checked' : '';
			echo '<label><input type="radio" name="answers[' . esc_attr($field_id) . ']" value="' . esc_attr($option) . '" ' . $checked . ' ' . $required . '> ' . esc_html($option) . '</label><br>';
		}
	} elseif ($field['type'] === 'number') {
		$min = isset($field['min']) ? ' min="' . intval($field['min']) . '"' : '';
		$max = isset($field['max']) ? ' max="' . intval($field['max']) . '"' : '';
		echo '<input type="number" id="' . esc_attr($field_id) . '" name="answers[' . esc_attr($field_id) . ']" value="' . esc_attr($value) . '"' . $min . $max . ' ' . $required . '>';
	}

	echo '</div>';
}

add_action('wp_ajax_save_section_answers', 'ajax_save_section_answers');
function ajax_save_section_answers() {
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in.');
    }

    if (empty($_POST['section_id']) || empty($_POST['answers'])) {
        wp_send_json_error('Missing data.');
    }

    $section_id = intval($_POST['section_id']);
    $user_id    = get_current_user_id();
    $answers    = array_map('sanitize_text_field', $_POST['answers']);

    // Fetch data and existing answers
    $data = get_section_data($section_id);
    if (!$data) {
        wp_send_json_error('Invalid section.');
    }
    list($user_answers, $existing_post_id) = get_existing_answers($user_id, $section_id);

    // Reuse your current submission logic
    list($error_message, $success, $score_message, $explanations, $user_answers) =
        handle_form_submission($data, $answers, $section_id, $user_id, $existing_post_id);

    // Send response
    if ($success) {
        wp_send_json_success([
            'message'      => $score_message ?: 'Answers saved successfully!',
            'explanations' => build_explanations_html($explanations)
        ]);
    } else {
        wp_send_json_error($error_message ?: 'Failed to save answers.');
    }
}




function save_quiz_score($user_id, $section_id, $correct, $total, $pdf_link = '') {
	if (empty($user_id) || empty($section_id)) {
		error_log('Missing user ID or section ID.');
		return;
	}

	$score_string = "$correct/$total";
	$current_date = date('Y-m-d');

	$args = [
		'post_type'   => 'lesson_answer',
		'post_status' => 'publish',
		'author'      => $user_id,
		'meta_query'  => [
			[
				'key'     => 's_id',
				'value'   => $section_id,
				'compare' => '='
			]
		],
		'numberposts' => 1
	];

	$existing_answers = get_posts($args);

	if (!empty($existing_answers)) {
		$existing_post_id = $existing_answers[0]->ID;
	} else {
		// 📄 Create new lesson_answer post
		$existing_post_id = wp_insert_post([
			'post_type'   => 'lesson_answer',
			'post_status' => 'publish',
			'post_author' => $user_id,
			'post_title'  => 'Answer for section ' . $section_id
		]);

		if (is_wp_error($existing_post_id)) {
			error_log('Failed to create lesson_answer post: ' . $existing_post_id->get_error_message());
			return;
		}

		update_post_meta($existing_post_id, 's_id', $section_id);
		update_post_meta($existing_post_id, 'userid', $user_id);
		update_post_meta($existing_post_id, 'is_completed', true);
	}

	// 🧠 Load existing scores
	$existing_scores_json = get_post_meta($existing_post_id, 'scores', true);
	$scores = [];
	if (!empty($existing_scores_json)) {
		$decoded = json_decode($existing_scores_json, true);
		if (is_array($decoded)) {
			$scores = $decoded;
		}
	}

	// ➕ Add new entry
	$new_entry = [
		'date'  => $current_date,
		'score' => $score_string
	];

	if (!empty($pdf_link)) {
		$new_entry['pdf'] = $pdf_link;
	}

	$oldest = count($scores) >= 3 ? $scores[0] : null;
	$scores[] = $new_entry;
	$scores = array_slice($scores, -3);

	update_post_meta($existing_post_id, 'scores', json_encode($scores));

	// 🗑️ Delete or archive old PDF
	if (!empty($oldest['pdf'])) {
		remove_pdf_by_url($oldest['pdf'], 'archive'); // or 'delete' if preferred
	}
}










function get_rating_feedback($section_id, $score_percent, $score_correct) {
	$scale_json = get_post_meta($section_id, 'rating_scale', true);
	if (!$scale_json) return '';

	$scale_data = json_decode($scale_json, true);
	if (!$scale_data || empty($scale_data['rating_scale'])) return '';

	foreach ($scale_data['rating_scale'] as $range) {
		if (
			isset($range['min_score'], $range['max_score']) &&
			$score_percent >= $range['min_score'] &&
			$score_percent <= $range['max_score']
		) {
			$title = esc_html($range['title']);
			$description = esc_html($range['description']);
			return "<h4>Results: {$title}</h4><p>$score_correct ($score_percent%)</p><p>{$description}</p>";
		}
	}

	return '';
}




function remove_pdf_by_url($pdf_url, $mode = 'delete') {
	if (empty($pdf_url)) return;

	$upload_dir = wp_upload_dir();

	// Normalize to relative path (strip domain if full URL)
	if (strpos($pdf_url, '/wp-content/uploads') !== 0) {
		$pdf_url = str_replace(home_url(), '', $pdf_url);
	}

	// Remove /wp-content/uploads to get relative file path
	$pdf_relative_path = str_replace('/wp-content/uploads', '', $pdf_url);
	$full_path         = $upload_dir['basedir'] . $pdf_relative_path;

	if (!file_exists($full_path)) return;

	if ($mode === 'delete') {
		unlink($full_path);
	} elseif ($mode === 'archive') {
		$dir      = dirname($full_path);
		$filename = basename($full_path);
		$archive_filename = 'archive-' . $filename;
		$archive_path     = $dir . '/' . $archive_filename;

		// Only rename if the archive version doesn't already exist
		if (!file_exists($archive_path)) {
			rename($full_path, $archive_path);
		}
	}
}
















/**
 * handle_generate_pdf_request
 *
 * Hooked into WordPress `template_redirect`, this function triggers when the user visits the
 * `/generate-pdf/` page with a valid `sid` (section ID) query parameter.
 *
 * It performs the following:
 * - Validates the request and user authentication.
 * - Fetches the saved lesson answers for the current user and section.
 * - Builds a prompt using those answers and sends it to OpenAI's API.
 * - Validates and decodes the JSON response from OpenAI.
 * - Substitutes dynamic placeholders into a stored PDF HTML template.
 * - Renders a completed PDF preview using the `render_section_pdf()` function.
 *
 * This is used to generate a personalized PDF based on a user's lesson responses.
 */

add_action('template_redirect', 'handle_generate_pdf_request');

function handle_generate_pdf_request() {
    if (!isset($_GET['sid']) || !is_user_logged_in()) return;

    // Only run on the /generate-pdf/ page (unless it's an AJAX call)
    if (!is_page('generate-pdf') && empty($_GET['ajax'])) return;

    $section_id = intval($_GET['sid']);
    $user_id    = get_current_user_id();

    // 🔍 Fetch the user's saved lesson_answer
    $query = new WP_Query([
        'post_type'   => 'lesson_answer',
        'post_status' => 'publish',
        'meta_query'  => [
            ['key' => 'userid', 'value' => $user_id],
            ['key' => 's_id',    'value' => $section_id],
        ]
    ]);

    if (!$query->have_posts()) {
        $error = '❌ No saved answers found for this section.';
        if (!empty($_GET['ajax'])) wp_send_json_error($error);
        echo $error;
        exit;
    }

    $post_id = $query->posts[0]->ID;

    // 🧠 Generate prompt and get OpenAI response
    $prompt = build_filled_prompt_from_section($section_id, $user_id);
    $openai_json = call_openai_api($prompt);

    if (empty($openai_json)) {
        $error = '❌ OpenAI response was empty.';
        if (!empty($_GET['ajax'])) wp_send_json_error($error);
        echo $error;
        exit;
    }

    update_post_meta($post_id, 'prompt_output', $openai_json);

    // 🧠 Decode OpenAI response
    $output_data = json_decode($openai_json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($output_data)) {
        error_log('🔴 JSON Decode Error: ' . json_last_error_msg());
        error_log('🔴 Raw OpenAI Response: ' . $openai_json);
        $error = '❌ Invalid JSON returned from OpenAI.';
        if (!empty($_GET['ajax'])) wp_send_json_error($error);
        echo $error;
        exit;
    }

    // 🚨 Check if OpenAI returned an error object
    if (isset($output_data['error'])) {
        error_log('🔴 OpenAI error: ' . print_r($output_data['error'], true));
        $error = '❌ OpenAI error: ' . esc_html($output_data['error']['message'] ?? 'Unknown error');
        if (!empty($_GET['ajax'])) wp_send_json_error($error);
        echo $error;
        exit;
    }

    error_log('✅ Decoded OpenAI Output: ' . print_r($output_data, true));

    // 📄 Fetch the PDF template from section meta
    $template_html = get_post_meta($section_id, 'pdf_template', true);
    if (!$template_html) {
        $error = '❌ No PDF template found.';
        if (!empty($_GET['ajax'])) wp_send_json_error($error);
        echo $error;
        exit;
    }

    // 🔁 Replace placeholders using flattening
    $flattened = flatten_placeholders($output_data);
    error_log('🧩 Available placeholders: ' . print_r(array_keys($flattened), true));

    foreach ($flattened as $key => $value) {
        $template_html = str_replace($key, $value, $template_html);
    }

    // ✅ Convert to TCPDF-safe HTML and render PDF
    $safe_html = convert_to_tcpdf_html($template_html);

    // Save HTML for preview
    update_post_meta($post_id, 'pdf_html', $safe_html);

    // Generate the PDF
    $pdf_url = render_section_pdf($output_data, $section_id, $safe_html, $user_id);

    // 🔁 Get existing scores from post meta
    $scores_json = get_post_meta($post_id, 'scores', true);
    $scores = json_decode($scores_json, true);

    // 🧩 Ensure it's an array
    if (!is_array($scores)) $scores = [];

    // 📌 Append the new entry
    $current_date = date('Y-m-d');
    $scores[] = [
        'date' => $current_date,
        'pdf'  => $pdf_url
    ];

    // 💾 Save back to post meta
    update_post_meta($post_id, 'scores', json_encode($scores));
    update_post_meta($post_id, 'pdf_link', $pdf_url);

    // ✅ If this was an AJAX request, return JSON
    if (!empty($_GET['ajax'])) {
        wp_send_json_success([
            'pdf_url'      => $pdf_url,
            'preview_html' => $safe_html
        ]);
    }

    // ✅ Redirect back to section form after PDF generation
    wp_redirect(home_url("/account/section/?sid={$section_id}&generated=1"));
    exit;
}













/**
 * get_lesson_answers_for_pdf
 *
 * Retrieves the saved answers JSON for a given user and section from the `lesson_answer` CPT.
 *
 * Steps:
 * - Queries for the user's `lesson_answer` post using `user_id` and `section_id` meta.
 * - Decodes the JSON from the `answers_json` meta field.
 * - Returns the decoded array of answers or an empty array if not found or invalid.
 *
 * @param int $user_id     The WordPress user ID.
 * @param int $section_id  The section (post) ID tied to the lesson.
 * @return array           Decoded answers as associative array, or empty array on failure.
 */

function get_lesson_answers_for_pdf($user_id, $section_id) {
	// 🔍 Query for the lesson_answer post for this user and section
	$query = new WP_Query([
		'post_type'      => 'lesson_answer',
		'posts_per_page' => 1,
		'meta_query'     => [
			[
				'key'     => 'userid',
				'value'   => $user_id,
				'compare' => '='
			],
			[
				'key'     => 's_id',
				'value'   => $section_id,
				'compare' => '='
			]
		]
	]);

	// ✅ If an answer is found, decode the JSON from post meta
	if ($query->have_posts()) {
		$query->the_post();
		$raw_json = get_post_meta(get_the_ID(), 'answers_json', true);
		wp_reset_postdata();

		// 🧠 Attempt to decode JSON
		$data = json_decode($raw_json, true);

		// 🚨 If JSON decoding failed, log the error
		if (json_last_error() !== JSON_ERROR_NONE) {
			error_log('🔴 Invalid JSON in lesson_answer for user_id ' . $user_id . ', section_id ' . $section_id);
			error_log('🔴 JSON error: ' . json_last_error_msg());
			error_log('🔴 Raw data: ' . $raw_json);
			return [];
		}

		// ✅ Return decoded answers
		return is_array($data) ? $data : [];
	}

	// 🚫 No answer found
	return [];
}









/**
 * build_filled_prompt_from_section
 *
 * Builds a personalized prompt for OpenAI based on a section template and user-submitted answers.
 *
 * Steps:
 * 1. Retrieves the prompt template stored in the section's `section_prompt` post meta.
 * 2. Looks up the user's saved answers from the `lesson_answer` CPT using the user ID and section ID.
 * 3. Parses the saved `answers_json` and replaces each `{field_id}` placeholder in the prompt template with the actual user input.
 * 4. Returns the filled-in prompt, ready to be sent to OpenAI.
 *
 * @param int $section_id  The ID of the section.
 * @param int $user_id     The ID of the current user.
 * @return string          The fully populated prompt or an error message string if data is missing.
 */

function build_filled_prompt_from_section($section_id, $user_id) {
	// 🔧 Load the prompt template from the section post meta
	$prompt_template = get_post_meta($section_id, 'section_prompt', true);
	if (!$prompt_template) return '⚠️ No prompt found for this section.';

	// 🔍 Query for the user's saved lesson_answer post for this section
	$answer_post = new WP_Query([
		'post_type'      => 'lesson_answer',
		'posts_per_page' => 1,
		'meta_query'     => [
			[
				'key'     => 'userid',
				'value'   => $user_id,
				'compare' => '='
			],
			[
				'key'     => 's_id',
				'value'   => $section_id,
				'compare' => '='
			]
		]
	]);

	// 🚫 If no answer is found, return a warning
	if (!$answer_post->have_posts()) return '⚠️ No saved answers found.';

	// 🎯 Get the post and load its answers
	$answer_post->the_post();
	$answers_json = get_post_meta(get_the_ID(), 'answers_json', true);
	wp_reset_postdata();

	if (!$answers_json) return '⚠️ No answer data available.';

	// 📤 Decode the JSON into an array
	$answers = json_decode($answers_json, true);
	if (!is_array($answers)) return '⚠️ Invalid answer data.';

	// 🔁 Replace placeholders like {field_id} in the prompt with actual answers
	foreach ($answers as $field_id => $value) {
		$placeholder = '{' . $field_id . '}';
		$prompt_template = str_replace($placeholder, $value, $prompt_template);
	}

	// ✅ Return the filled prompt
	return $prompt_template;
}





function render_section_pdf($answers, $section_id, $filled_prompt_html, $user_id) {
	require_once get_stylesheet_directory() . '/tcpdf/tcpdf.php';

	// 📚 Get related lesson and module info
	$lesson_id     = get_post_meta($section_id, 'lesson_id', true);
	$module_id     = get_post_meta($lesson_id, 'module_id', true);
	$module_title  = get_the_title($module_id);
	$lesson_title  = get_the_title($lesson_id);
	$section_title = get_the_title($section_id);
	$section_title_slug = sanitize_title($section_title);
	$module_number = get_post_meta($module_id, 'module_number', true);
	$lesson_number = get_post_meta($lesson_id, 'lesson_order', true);

	// 🧾 Extend TCPDF for header/footer
	class CustomPDF extends TCPDF {
		public $module_number;
		public $lesson_number;
		public $lesson_title;

		public function Header() {
			$this->SetY(10);
			$this->SetFont('helvetica', 'B', 9);
			$header_text = 'Module ' . $this->module_number . ' | Lesson ' . $this->lesson_number . ': ' . $this->lesson_title;
			$this->Cell(0, 10, $header_text, 0, 1, 'L');
		}

		public function Footer() {
			$this->SetY(-15);
			$this->SetFont('helvetica', 'I', 9);
			$date = date('m/d/Y');
			$this->Cell(0, 10, $date, 0, 0, 'L');
			$this->Cell(0, 10, 'Playbook Generator | ' . $this->getAliasNumPage(), 0, 0, 'R');
		}
	}

	// 🔐 Sanitize and convert HTML
	$safe_html = convert_to_tcpdf_html($filled_prompt_html);

	// 🧾 Init PDF
	$pdf = new CustomPDF();
	$pdf->SetCreator(PDF_CREATOR);
	$pdf->SetAuthor('RLA');
	$pdf->SetTitle('RLA Playbook Generator');

	$pdf->module_number = $module_number;
	$pdf->lesson_number = $lesson_number;
	$pdf->lesson_title  = $lesson_title;

	$pdf->SetMargins(20, 20, 20);
	$pdf->AddPage();
	$pdf->SetFont('helvetica', '', 12);

	// 🧠 Build header content
	$header = '
		<h1 style="margin-bottom:10px;">' . esc_html($section_title) . '</h1>
		<hr />
	';

	$pdf->writeHTML($header, true, false, true, false, '');
	$pdf->writeHTML($safe_html, true, false, true, false, '');

	// 💾 Save PDF to disk
	// 💾 Save PDF to disk
	$upload_dir = wp_upload_dir();
	$directory = trailingslashit($upload_dir['basedir']) . 'generated_pdfs/';
	$url_base  = trailingslashit($upload_dir['baseurl']) . 'generated_pdfs/';

	if (!file_exists($directory)) wp_mkdir_p($directory);

	$filename  = 'M' . $module_number . 'L' . $lesson_number . '_' . $section_title_slug . '_user' . $user_id . '_' . date('Ymd_His') . '.pdf';
	$filepath  = $directory . $filename;

	// ✅ Clear any previous output to avoid TCPDF error
	if (ob_get_length()) {
		ob_end_clean();
	}
	ob_start(); // Restart clean buffer

	$pdf->Output($filepath, 'F'); // Save to file

	// 🌐 Return public URL
	return str_replace(site_url(), '', $url_base . $filename);


}








function convert_to_tcpdf_html($html) {
	// Remove unsupported tags like <div> and <span> by converting to <p>
	$html = preg_replace('/<div[^>]*>/', '', $html);
	$html = str_replace('</div>', '', $html);
	$html = preg_replace('/<span[^>]*>/', '', $html);
	$html = str_replace('</span>', '', $html);

	// Replace <br> without slash to valid <br />
	$html = str_replace('<br>', '<br />', $html);

	// Optional: clean any script/style
	$html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
	$html = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $html);

	return $html;
}









/**
 * call_openai_api
 *
 * Sends a prompt to OpenAI's GPT-4 chat completion endpoint and attempts to extract a valid JSON response.
 *
 * Steps:
 * 1. Constructs an HTTP POST request with the given prompt, formatted for a conversational AI.
 * 2. Sends the request to OpenAI using the GPT-4 model.
 * 3. Validates the response and attempts to extract and return valid JSON content from it.
 * 4. If the response is malformed or doesn't contain valid JSON, returns a fallback JSON error message.
 *
 * @param string $prompt The user-defined input to send to OpenAI.
 * @return string A valid JSON string from the AI response, or a fallback JSON error message.
 */



/**
 * call_openai_api
 *
 * Sends a prompt to OpenAI's GPT-4 chat completion endpoint and returns a valid JSON response.
 * Logs errors and trims response to return clean JSON.
 *
 * @param string $prompt The prompt to send to OpenAI.
 * @return string Valid JSON string or a JSON-encoded error object.
 */
function call_openai_api($prompt) {
	$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null;

	if (!$api_key) {
		error_log('🔴 Missing OpenAI API key.');
		log_openai_error('🔴 Missing OpenAI API key.', 'Function: call_openai_api | User: ' . get_current_user_id());
		return json_encode(['error' => 'Missing API key.']);
	}

	// 📦 Prepare API request
	$args = [
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'OpenAI-Beta'   => 'assistants=v2',
		],
		'body' => json_encode([
			'model' => 'gpt-4',
			'messages' => [
				[
					'role'    => 'system',
					'content' => 'You are a helpful assistant that gives professional coaching advice.'
				],
				[
					'role'    => 'user',
					'content' => $prompt
				]
			],
			'temperature' => 0.7,
			'max_tokens'  => 2000
		]),
		'timeout' => 30
	];

	$response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);

	// 🚨 Check for request failure
	if (is_wp_error($response)) {
		$error_message = $response->get_error_message();
		error_log("🔴 OpenAI API request failed: $error_message");
		log_openai_error('🔴 OpenAI API request failed.', $error_message);
		return json_encode(['error' => 'OpenAI request failed.', 'details' => $error_message]);
	}

	// 📖 Parse the API response
	$body_raw = wp_remote_retrieve_body($response);
	$body     = json_decode($body_raw, true);

	if (!isset($body['choices'][0]['message']['content'])) {
		error_log('🔴 Unexpected OpenAI API response structure: ' . $body_raw);
		log_openai_error('🔴 Unexpected OpenAI API response structure:', $body_raw);
		return json_encode(['error' => 'Invalid OpenAI response structure.']);
	}

	$content = trim($body['choices'][0]['message']['content']);
	error_log('🟡 Raw OpenAI content: ' . $content);
	log_openai_error('🟡 Raw OpenAI content:', $content);

	// 🧠 Try to extract valid JSON from response string
	$json = extract_json_from_string($content);
	if ($json !== null) {
		return $json;
	}

	// 🚨 Fallback
	error_log('🔴 Failed to extract valid JSON from OpenAI response.');
	log_openai_error('🔴 Failed to extract valid JSON from OpenAI response.', $content);
	return json_encode(['error' => 'Invalid JSON response from OpenAI.']);
}


/**
 * extract_json_from_string
 *
 * Attempts to extract a valid JSON object or array from a string.
 * Strips markdown formatting like ```json and trims content.
 *
 * @param string $text Input string (may contain text, markdown, or code fences)
 * @return string|null Valid JSON string or null if none found
 */
function extract_json_from_string($text) {
	$text = trim($text);

	// Remove markdown ```json fences
	$text = preg_replace('/^```json\s*|\s*```$/', '', $text);
	$text = trim($text);

	// Attempt to find the first JSON object or array
	$start_object = strpos($text, '{');
	$end_object   = strrpos($text, '}');

	$start_array = strpos($text, '[');
	$end_array   = strrpos($text, ']');

	$candidates = [];

	if ($start_object !== false && $end_object !== false && $end_object > $start_object) {
		$candidates[] = substr($text, $start_object, $end_object - $start_object + 1);
	}

	if ($start_array !== false && $end_array !== false && $end_array > $start_array) {
		$candidates[] = substr($text, $start_array, $end_array - $start_array + 1);
	}

	// Check all candidates for valid JSON
	foreach ($candidates as $candidate) {
		$decoded = json_decode($candidate, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $candidate;
		}
	}

	return null;
}










function flatten_placeholders($array, $prefix = '') {
	$result = [];
	foreach ($array as $key => $value) {
		$full_key = $prefix ? "{$prefix}.{$key}" : $key;
		if (is_array($value)) {
			$result += flatten_placeholders($value, $full_key);
		} else {
			$result["{{{$full_key}}}"] = $value;
		}
	}
	return $result;
}
