<?php
// Requires: $progress (admission_progress row), $currentStep (int 1-4)
$stepDefs = [
    1 => ['label'=>'Department',        'icon'=>'bi-building',               'link'=>'step1_dept.php'],
    2 => ['label'=>'Hall',              'icon'=>'bi-house-fill',             'link'=>'step2_hall.php'],
    
    3 => ['label'=>'Registrar',         'icon'=>'bi-patch-check-fill',       'link'=>'step3_registrar.php'],
];
$stepFields = [
    1 => ['field'=>'dept_step',      'done'=>'Done'],
    2 => ['field'=>'hall_step',      'done'=>'Done'],
    
    3 => ['field'=>'registrar_step', 'done'=>'Approved'],
];
?>
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row justify-content-center g-2 text-center">
            <?php foreach ($stepDefs as $num => $step):
                $val      = $progress[$stepFields[$num]['field']] ?? 'Pending';
                $isDone   = ($val === $stepFields[$num]['done']);
                $isRej    = ($val === 'Rejected');
                $isActive = (isset($currentStep) && $currentStep === $num);
                $bg       = $isDone ? 'bg-success' : ($isRej ? 'bg-danger' : ($isActive ? 'bg-primary' : 'bg-light border'));
            ?>
            <div class="col-4">
                <a href="/jkkniu-admission/student/<?= $step['link'] ?>" class="text-decoration-none">
                    <div class="d-flex flex-column align-items-center gap-1">
                        <div class="rounded-circle d-flex align-items-center justify-content-center <?= $bg ?>"
                             style="width:46px;height:46px;">
                            <?php if ($isDone): ?>
                                <i class="bi bi-check-lg text-white fs-5"></i>
                            <?php elseif ($isRej): ?>
                                <i class="bi bi-x-lg text-white fs-5"></i>
                            <?php else: ?>
                                <span class="fw-bold <?= $isActive ? 'text-white' : 'text-muted' ?>">
                                    <?= $num ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <small class="fw-semibold <?= 
                            $isDone ? 'text-success' : 
                            ($isRej ? 'text-danger' : 
                            ($isActive ? 'text-primary' : 'text-muted')) ?>">
                            <?= $step['label'] ?>
                        </small>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>