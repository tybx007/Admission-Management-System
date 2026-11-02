<?php
$pageTitle = "Public Noticeboard";
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/header.php';
require_once 'includes/navbar.php';
require_once 'includes/notice_dynamic_content.php';

$notices = $pdo->query("
    SELECT n.*, d.dept_name
    FROM admission_notices n
    LEFT JOIN departments d ON n.dept_id = d.dept_id
    ORDER BY n.published_at DESC, n.admission_start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="noticeboard-hero py-5">
    <div class="container text-center">
        <h1 class="fw-bold mb-2">Admission Noticeboard</h1>
        <p class="mb-4 text-muted">
            Stay updated with admission circulars, deadlines, and department-wise announcements.
        </p>

        <div class="notice-search-wrap mx-auto">
            <input type="text" id="noticeSearch" class="form-control form-control-lg shadow-sm"
                   placeholder="Search notices by title, body, or department...">
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!empty($notices)): 
        $latest = $notices[0];
    ?>
        <div class="featured-notice card border-0 shadow-lg mb-4">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <span class="badge bg-warning text-dark mb-2">Latest Notice</span>
                        <h3 class="mb-2"><?= htmlspecialchars($latest['notice_title']) ?></h3>
                        <div class="small text-muted mb-2">
                            Published:
                            <?= !empty($latest['published_at']) ? date('F j, Y, g:i A', strtotime($latest['published_at'])) : '—' ?>
                        </div>
                        <div class="mb-3">
                            <?php if (!empty($latest['dept_id'])): ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($latest['dept_name'] ?? 'Department') ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">All Departments</span>
                            <?php endif; ?>
                        </div>
                        <p class="mb-0 text-secondary">
                            <?= htmlspecialchars(mb_strimwidth($latest['notice_body'], 0, 220, '...')) ?>
                        </p>
                    </div>

                    <div>
                        <button
                            class="btn btn-primary px-4 py-2 notice-view-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#noticeModal"
                            data-title="<?= htmlspecialchars($latest['notice_title'], ENT_QUOTES) ?>"
                            data-body="<?= htmlspecialchars($latest['notice_body'], ENT_QUOTES) ?>"
                            data-dept="<?= htmlspecialchars(!empty($latest['dept_id']) ? ($latest['dept_name'] ?? '—') : 'All Departments', ENT_QUOTES) ?>"
                            data-start="<?= !empty($latest['admission_start_date']) ? date('F j, Y', strtotime($latest['admission_start_date'])) : '—' ?>"
                            data-end="<?= !empty($latest['admission_end_date']) ? date('F j, Y', strtotime($latest['admission_end_date'])) : 'Not specified' ?>"
                            data-published="<?= $latest['published_at'] ? date('F j, Y h:i A', strtotime($latest['published_at'])) : '—' ?>"
                            data-notice-id="<?= (int)$latest['notice_id'] ?>"
                            data-notice-type="<?= htmlspecialchars($latest['notice_type'] ?? 'Text', ENT_QUOTES) ?>"
                        >
                            Read Full Notice
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4" id="noticeGrid">
        <?php if (empty($notices)): ?>
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5 text-muted">
                        No notices available right now.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($notices as $notice): ?>
                <div class="col-md-6 col-lg-4 notice-item">
                    <div class="card notice-ui-card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <div class="mb-2 d-flex justify-content-between align-items-start gap-2">
                                <?php if (!empty($notice['dept_id'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border">
                                        <?= htmlspecialchars($notice['dept_name'] ?? 'Department') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">All Departments</span>
                                <?php endif; ?>

                                <span class="notice-date-badge">
                                    <?= !empty($notice['published_at']) ? date('M j', strtotime($notice['published_at'])) : '—' ?>
                                </span>
                            </div>

                            <h5 class="notice-title mb-2">
                                <?= htmlspecialchars($notice['notice_title']) ?>
                            </h5>

                            <div class="small text-muted mb-2">
                                <i class="bi bi-calendar-event me-1"></i>
                                <?= !empty($notice['admission_start_date']) ? date('M j, Y', strtotime($notice['admission_start_date'])) : '—' ?>
                                <?php if (!empty($notice['admission_end_date'])): ?>
                                    — <?= date('M j, Y', strtotime($notice['admission_end_date'])) ?>
                                <?php endif; ?>
                            </div>

                            <p class="notice-preview text-secondary flex-grow-1">
                                <?= htmlspecialchars(mb_strimwidth($notice['notice_body'], 0, 140, '...')) ?>
                            </p>

                            <button
                                class="btn btn-outline-primary mt-2 notice-view-btn"
                                data-bs-toggle="modal"
                                data-bs-target="#noticeModal"
                                data-title="<?= htmlspecialchars($notice['notice_title'], ENT_QUOTES) ?>"
                                data-body="<?= htmlspecialchars($notice['notice_body'], ENT_QUOTES) ?>"
                                data-dept="<?= htmlspecialchars(!empty($notice['dept_id']) ? ($notice['dept_name'] ?? '—') : 'All Departments', ENT_QUOTES) ?>"
                                data-start="<?= !empty($notice['admission_start_date']) ? date('F j, Y', strtotime($notice['admission_start_date'])) : '—' ?>"
                                data-end="<?= !empty($notice['admission_end_date']) ? date('F j, Y', strtotime($notice['admission_end_date'])) : 'Not specified' ?>"
                                data-published="<?= $notice['published_at'] ? date('F j, Y h:i A', strtotime($notice['published_at'])) : '—' ?>"
                                data-notice-id="<?= (int)$notice['notice_id'] ?>"
                                data-notice-type="<?= htmlspecialchars($notice['notice_type'] ?? 'Text', ENT_QUOTES) ?>"
                            >
                                Read More
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="noticeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--jkkniu-primary);">
                <h5 class="modal-title">Notice Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <h3 id="modalNoticeTitle" class="mb-3"></h3>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="notice-meta-box">
                            <small class="text-muted d-block">Department</small>
                            <strong id="modalNoticeDept"></strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="notice-meta-box">
                            <small class="text-muted d-block">Start Date</small>
                            <strong id="modalNoticeStart"></strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="notice-meta-box">
                            <small class="text-muted d-block">End Date</small>
                            <strong id="modalNoticeEnd"></strong>
                        </div>
                    </div>
                </div>

                <div class="mb-3 small text-muted">
                    Published: <span id="modalNoticePublished"></span>
                </div>

                <div class="notice-full-body" id="modalNoticeBody" style="white-space: pre-line;"></div>

                <!-- Dynamic content:
                     Link / Merit List / Waiting List / Department Vacancy -->
                <div id="modalNoticeDynamic" class="mt-4"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.notice-view-btn').forEach(button => {
    button.addEventListener('click', async function () {

        document.getElementById('modalNoticeTitle').textContent = this.dataset.title;
        document.getElementById('modalNoticeDept').textContent = this.dataset.dept;
        document.getElementById('modalNoticeStart').textContent = this.dataset.start;
        document.getElementById('modalNoticeEnd').textContent = this.dataset.end;
        document.getElementById('modalNoticePublished').textContent = this.dataset.published;
        document.getElementById('modalNoticeBody').textContent = this.dataset.body;

        const dynamicBox = document.getElementById('modalNoticeDynamic');
        const noticeId = this.dataset.noticeId;
        const noticeType = this.dataset.noticeType || 'Text';

        // Text notices need no extra database-rendered section.
        if (noticeType === 'Text') {
            dynamicBox.innerHTML = '';
            return;
        }

        dynamicBox.innerHTML = `
            <div class="text-center py-3 text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Loading ${noticeType}...
            </div>
        `;

        try {
            const response = await fetch(
                'api/notice_dynamic.php?notice_id=' + encodeURIComponent(noticeId),
                {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            if (!response.ok) {
                throw new Error('Unable to load notice content.');
            }

            const html = await response.text();
            dynamicBox.innerHTML = html;

        } catch (error) {
            dynamicBox.innerHTML = `
                <div class="alert alert-danger mb-0">
                    The ${noticeType} could not be loaded. Please try again.
                </div>
            `;
        }
    });
});

document.getElementById('noticeSearch').addEventListener('input', function () {
    const keyword = this.value.toLowerCase();
    document.querySelectorAll('.notice-item').forEach(item => {
        const text = item.innerText.toLowerCase();
        item.style.display = text.includes(keyword) ? '' : 'none';
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>