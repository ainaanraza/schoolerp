<?php

function get_upfront_periods_for_cycle(string $billingCycle): array
{
    return match ($billingCycle) {
        'monthly' => [
            'Month 1', 'Month 2', 'Month 3', 'Month 4',
            'Month 5', 'Month 6', 'Month 7', 'Month 8',
            'Month 9', 'Month 10', 'Month 11', 'Month 12'
        ],
        'quarterly' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
        'half_yearly' => ['Half 1', 'Half 2'],
        'yearly', 'one_time' => ['Installment 1'],
        default => ['Installment 1']
    };
}

function auto_generate_upfront_fees(PDO $pdo, int $feeStructureId): int
{
    $structureStatement = $pdo->prepare('SELECT id, class_id, session_id, amount, billing_cycle, due_day FROM fee_structures WHERE id = :id AND is_active = 1 LIMIT 1');
    $structureStatement->execute(['id' => $feeStructureId]);
    $structure = $structureStatement->fetch();
    
    if (!$structure) return 0;
    
    $studentsStatement = $pdo->prepare('
        SELECT s.id 
        FROM student_class_enrollments sce 
        JOIN students s ON s.id = sce.student_id 
        WHERE sce.class_id = :class_id AND sce.session_id = :session_id AND sce.is_active = 1 AND s.status = "enrolled"
    ');
    $studentsStatement->execute([
        'class_id' => $structure['class_id'],
        'session_id' => $structure['session_id']
    ]);
    $students = $studentsStatement->fetchAll();
    
    if (empty($students)) return 0;
    
    $periods = get_upfront_periods_for_cycle((string)$structure['billing_cycle']);
    
    $checkFee = $pdo->prepare('SELECT id FROM student_fees WHERE student_id = :student_id AND fee_structure_id = :fee_structure_id AND period_label = :period_label LIMIT 1');
    $insertFee = $pdo->prepare('
        INSERT INTO student_fees (student_id, fee_structure_id, period_label, total_amount, discount_amount, payable_amount, paid_amount, status, due_date) 
        VALUES (:student_id, :fee_structure_id, :period_label, :total_amount, 0, :payable_amount, 0, "pending", :due_date)
    ');
    
    $count = 0;
    foreach ($students as $student) {
        foreach ($periods as $period) {
            $checkFee->execute([
                'student_id' => $student['id'],
                'fee_structure_id' => $feeStructureId,
                'period_label' => $period
            ]);
            if (!$checkFee->fetch()) {
                $insertFee->execute([
                    'student_id' => $student['id'],
                    'fee_structure_id' => $feeStructureId,
                    'period_label' => $period,
                    'total_amount' => $structure['amount'],
                    'payable_amount' => $structure['amount'],
                    'due_date' => null
                ]);
                $count++;
            }
        }
    }
    return $count;
}
