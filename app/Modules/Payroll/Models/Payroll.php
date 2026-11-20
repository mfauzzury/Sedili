<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\Audited;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use Audited;

    protected $table = 'payrolls';
    protected $fillable = [
        'employee_id',
        'pay_date',
        'period',
        'gross_pay',
        'net_pay',
        'deductions',
        'allowances',
        'bonuses',
        'notes',
        'status'
    ];
    protected $casts = [
        'deductions' => 'array',
        'allowances' => 'array',
        'bonuses' => 'array',
    ];

    protected $with = [
        'employee',
    ];
    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }


}
