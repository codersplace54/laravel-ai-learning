<?php

namespace App\Exports;

use App\Models\UserFeedback;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Illuminate\Support\Facades\Auth;

class ServiceFeedbackExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $user = Auth::user();

        $query = UserFeedback::with(
            'user:id,user_name',
            'service:id,service_title_or_description,department_id'
        );

        if ($user->user_type === 'department') {
            $query->where('department_id', $user->id);
        }

        if ($user->user_type === 'individual') {
            $query->where('user_id', $user->id);
        }

        if (!empty($this->filters['department_id'])) {
            $query->where('department_id', $this->filters['department_id']);
        }

        if (!empty($this->filters['service_id'])) {
            $query->where('service_id', $this->filters['service_id']);
        }

        if (!empty($this->filters['from_date'])) {
            $query->where('created_at', '>=', $this->filters['from_date'] . ' 00:00:00');
        }

        if (!empty($this->filters['to_date'])) {
            $query->where('created_at', '<=', $this->filters['to_date'] . ' 23:59:59');
        }

        if (!empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('user_name', 'like', '%' . $search . '%');
                })->orWhere('feedback', 'like', '%' . $search . '%');
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'Username',
            'Service',
            'Satisfaction Rating',
            'Feedback',
            'Suggestions',
            'Submitted On',
        ];
    }

    public function map($feedback): array
    {
        return [
            $feedback->user->user_name ?? null,
            $feedback->service->service_title_or_description ?? null,
            $feedback->satisfaction,
            $feedback->feedback,
            $feedback->suggestions,
            $feedback->created_at,
        ];
    }
}