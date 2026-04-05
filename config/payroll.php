<?php

return [
    'default_overtime_rate_per_event' => (float) env('PAYROLL_DEFAULT_OVERTIME_RATE_PER_EVENT', 0),
    'cuti_deduction_amount' => (float) env('PAYROLL_CUTI_DEDUCTION_AMOUNT', 0),
];
