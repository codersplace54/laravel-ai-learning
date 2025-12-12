<?php

namespace App\Services;

use App\Models\ServiceQuestionnaire;
use App\Models\UserServiceApplication;

class ApplicationDataFormatter
{
    public function build_application_view_data(UserServiceApplication $application): array
    {
        $raw = $application->application_data;

        $application_data = is_array($raw) ? $raw : (json_decode($raw, true) ?: []);


        if (empty($application_data) || ! is_array($application_data)) {
            return [];
        }

        $question_ids = $this->collect_question_ids($application_data);

        if (empty($question_ids)) {
            return [];
        }

        $questions = ServiceQuestionnaire::whereIn('id', $question_ids)
            ->get(['id', 'question_label', 'question_type', 'display_order'])
            ->keyBy('id');

        $single_items  = [];
        $section_items = [];

        foreach ($application_data as $key => $value) {

            if (is_numeric($key)) {
                $question_id = (int) $key;
                $question    = $questions->get($question_id);
                $order       = $question->display_order ?? PHP_INT_MAX;

                $single_items[] = [
                    'order' => $order,
                    'data'  => $this->format_single_answer($question_id, $value, $questions),
                ];
                continue;
            }

            if (is_string($key) && is_array($value)) {
                $section_items[$key] = $this->format_section_rows($value, $questions);
            }
        }

        usort($single_items, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        $formatted_data = [];

        foreach ($single_items as $item) {
            $formatted_data[] = $item['data'];
        }

        foreach ($section_items as $section_name => $rows) {
            $formatted_data[$section_name] = $rows;
        }

        return $formatted_data;
    }

    public function collect_question_ids(array $data): array
    {
        $question_ids = [];

        foreach ($data as $key => $value) {

            if (is_numeric($key)) {
                $question_ids[] = (int) $key;
            }

            if (is_array($value)) {
                $question_ids = array_merge($question_ids, $this->collect_question_ids($value));
            }
        }

        return $question_ids;
    }

    public function format_single_answer(int $question_id, $answer, $questions): array
    {
        $question = $questions->get($question_id);

        if (
            $question &&
            $question->question_type === 'file' &&
            is_string($answer) &&
            $answer !== ''
        ) {
            if (! str_starts_with($answer, 'http')) {
                $answer = asset('storage/' . ltrim($answer, '/'));
            }
        }

        return [
            'id'       => $question_id,
            'question' => $question->question_label ?? null,
            'answer'   => $answer,
            'type'     => $question->question_type ?? null,
        ];
    }

    public function format_section_rows(array $rows, $questions): array
    {
        $formatted_rows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $field_items     = [];
            $nested_sections = [];

            foreach ($row as $key => $value) {

                if (is_numeric($key)) {
                    $question_id = (int) $key;
                    $question    = $questions->get($question_id);
                    $order       = $question->display_order ?? PHP_INT_MAX;

                    $field_items[] = [
                        'order' => $order,
                        'data'  => $this->format_single_answer($question_id, $value, $questions),
                    ];
                    continue;
                }

                if (is_string($key) && is_array($value)) {
                    $nested_sections[$key] = $this->format_section_rows($value, $questions);
                }
            }

            usort($field_items, function ($a, $b) {
                return $a['order'] <=> $b['order'];
            });

            $formatted_row = [];

            foreach ($field_items as $item) {
                $formatted_row[] = $item['data'];
            }

            foreach ($nested_sections as $name => $nested_rows) {
                $formatted_row[$name] = $nested_rows;
            }

            $formatted_rows[] = $formatted_row;
        }

        return $formatted_rows;
    }
}
