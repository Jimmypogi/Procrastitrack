<?php
require_once __DIR__ . '/checkin_support.php';

function checkin_values(array $input): array {
    $type = is_string($input['checkin_type'] ?? null) ? trim($input['checkin_type']) : '';
    $questions = checkin_questions();
    $values = ['checkin_type' => $type];
    $responses = [];
    $parts = [];
    $error = '';

    if ($type !== 'Weekly' || !isset($questions[$type])) {
        return [$values, 'Only the weekly check-in is available.', ['task_id' => null, 'reason' => '', 'mood' => null, 'focus' => null, 'energy' => null, 'notes' => '']];
    }

    foreach ($questions[$type] as $field => $question) {
        $raw = $input[$field] ?? '';
        if (!is_string($raw)) {
            $error = 'Please use the provided choices and text fields.';
            $answer = '';
        } else {
            $answer = trim($raw);
        }

        if (strlen($answer) > 5000) {
            $error = 'Please shorten each written answer to at most 5,000 bytes.';
        } elseif (($question['type'] ?? '') === 'textarea') {
            if (($question['required'] ?? false) && $answer === '') {
                $error = 'Please answer: ' . $question['question'];
            }
        } elseif ($answer === '' || !in_array($answer, $question['options'], true)) {
            $error = 'Choose a valid answer for: ' . $question['question'];
        }

        $values[$field] = $answer;
        $responses[$field] = ['question' => $question['question'], 'answer' => $answer];
        if ($answer !== '') {
            $parts[] = $question['question'] . ': ' . $answer;
        }
    }

    $values['responses'] = $responses;
    $values['recommendation'] = checkin_recommendation($values);
    $record = [
        'task_id' => null,
        'reason' => implode("\n", $parts),
        'mood' => null,
        'focus' => null,
        'energy' => null,
        'notes' => $type . ': Behavior check-in',
    ];

    return [$values, $error, $record];
}
