(function () {
    if (!document.getElementById('fetchHscBtn')) return;
    document.addEventListener('DOMContentLoaded', function () {

        // ── Flags & State ─────────────────────────────────────
        let personalDataFilled = false;
        let fetchedHscGroup    = '';


        // ════════════════════════════════════════════════════════
        // EDUBOARD FETCH
        // ════════════════════════════════════════════════════════

        function fetchEduBoard(exam_type, exam_year, board, roll, reg,
                               alertId, iconId, btnId, prefix) {

            const alertBox = document.getElementById(alertId);
            const btn      = document.getElementById(btnId);
            const icon     = document.getElementById(iconId);

            if (!exam_year || !board || !roll) {
                alertBox.innerHTML = `
                    <div class="alert alert-warning py-2 mt-2">
                        <i class="bi bi-exclamation-circle"></i>
                        Please fill <strong>Year, Board and Roll</strong> at minimum.
                    </div>`;
                return;
            }

            btn.disabled   = true;
            icon.className = 'spinner-border spinner-border-sm';
            alertBox.innerHTML = '';
            const sessionId =
                    document.getElementById(prefix + "_session_id").value;

                const captcha =
                    document.getElementById(prefix + "_captcha").value.trim();

                if (!sessionId) {

                    Swal.fire({
                        icon: "warning",
                        title: "CAPTCHA expired",
                        text: "Please refresh the CAPTCHA."
                    });

                    return;
                }

                if (!captcha) {

                    Swal.fire({
                        icon: "warning",
                        title: "Enter CAPTCHA",
                        text: "Please enter the CAPTCHA."
                    });

                    return;
                }
            fetch('/jkkniu-admission/api/fetch_eduboard.php', {
                method  : 'POST',
                headers : { 'Content-Type': 'application/json' },
                body: JSON.stringify({

                    exam_type,
                    exam_year,
                    board,
                    roll,
                    reg,

                    session_id: document.getElementById(prefix + "_session_id").value,

                    captcha: document.getElementById(prefix + "_captcha").value

                })
            })
            .then(res => res.text())
            .then(raw => {
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    alertBox.innerHTML = `
                        <div class="alert alert-danger py-2 mt-2">
                            <i class="bi bi-bug-fill"></i>
                            <strong>Server error.</strong>
                            <br><code style="font-size:11px">${raw.substring(0, 300)}</code>
                        </div>`;
                    return;
                }

                if (data.success) {

                    // ── Exam-specific fields ──────────────────
                    setField(prefix + '_roll',      data.roll);
                    setField(prefix + '_reg',       data.reg);
                    setField(prefix + '_board',     data.board_name);
                    setField(prefix + '_year',      data.passing_year);
                    setField(prefix + '_gpa',       data.gpa);
                    setField(prefix + '_institute', data.institute_name);

                    // ── HSC group — store & validate ──────────
                    if (exam_type === 'hsc') {
                        setField('hsc_group', data.group);
                        fetchedHscGroup = data.group || '';
                        applyGroupValidation(fetchedHscGroup);
                    }

                    // ── Personal fields — first fetch only ────
                    if (!personalDataFilled) {
                        setField('full_name',        data.full_name);
                        setField('father_name',       data.father_name);
                        setField('mother_name',       data.mother_name);
                        setDateField('date_of_birth', data.date_of_birth);
                        personalDataFilled = true;

                        const card   = document.getElementById('personalInfoCard');
                        const header = document.getElementById('personalInfoHeader');
                        if (card)   card.classList.replace('border-warning', 'border-success');
                        if (header) {
                            header.classList.replace('bg-warning', 'bg-success');
                            header.classList.replace('text-dark',  'text-white');
                        }
                    }

                    alertBox.innerHTML = `
                        <div class="alert alert-success py-2 mt-2">
                            <i class="bi bi-check-circle-fill"></i>
                            <strong>${exam_type.toUpperCase()} data fetched!</strong>
                            &nbsp; GPA: <strong>${data.gpa    || 'N/A'}</strong>
                            &nbsp;|&nbsp; Result: <strong>${data.result || 'N/A'}</strong>
                            &nbsp;|&nbsp; Group: <strong>${data.group  || 'N/A'}</strong>
                            ${personalDataFilled && exam_type === 'ssc'
                                ? '<br><small class="text-muted">Personal info already filled from HSC — edit manually if SSC data differs.</small>'
                                : '<br><small class="text-muted">Personal info auto-filled — please review and correct if needed.</small>'
                            }
                        </div>`;

                } else {
                    alertBox.innerHTML = `
                        <div class="alert alert-danger py-2 mt-2">
                            <i class="bi bi-x-circle-fill"></i>
                            <strong>Failed:</strong> ${data.message}
                            ${data.received
                                ? `<br><small>Received: ${JSON.stringify(data.received)}</small>`
                                : ''}
                            <br><small>You can fill the fields manually below.</small>
                        </div>`;
                }
            })
            .catch(err => {
                alertBox.innerHTML = `
                    <div class="alert alert-danger py-2 mt-2">
                        <i class="bi bi-wifi-off"></i>
                        <strong>Network error:</strong> ${err.message}
                    </div>`;
            })
            .finally(() => {
                btn.disabled   = false;
                icon.className = 'bi bi-search';
            });
        }

        // ── HSC Button ────────────────────────────────────────
        const fetchHscBtn = document.getElementById('fetchHscBtn');
        if (fetchHscBtn) {
            fetchHscBtn.addEventListener('click', function () {
                fetchEduBoard(
                    'hsc',
                    document.getElementById('hscYear').value.trim(),
                    document.getElementById('hscBoard').value.trim(),
                    document.getElementById('hscRoll').value.trim(),
                    document.getElementById('hscReg').value.trim(),
                    'hscAlert', 'hscFetchIcon', 'fetchHscBtn', 'hsc'
                );
            });
        }

        // ── SSC Button ────────────────────────────────────────
        const fetchSscBtn = document.getElementById('fetchSscBtn');
        if (fetchSscBtn) {
            fetchSscBtn.addEventListener('click', function () {
                fetchEduBoard(
                    'ssc',
                    document.getElementById('sscYear').value.trim(),
                    document.getElementById('sscBoard').value.trim(),
                    document.getElementById('sscRoll').value.trim(),
                    document.getElementById('sscReg').value.trim(),
                    'sscAlert', 'sscFetchIcon', 'fetchSscBtn', 'ssc'
                );
            });
        }


        // ════════════════════════════════════════════════════════
        // HSC GROUP — FACULTY VALIDATION
        // Rule: Science → all faculties allowed
        //       Others  → all faculties EXCEPT Science & Engineering
        // ════════════════════════════════════════════════════════

        function applyGroupValidation(group) {
            removeScienceGroupWarning();
            if (!group) return;

            const isScience  = group.toLowerCase().includes('science');
            const wrapper    = document.getElementById('facultySelectWrapper');
            const scienceOpt = document.querySelector(
                `#facultySelect option[value="${SCIENCE_FACULTY_ID}"]`
            );

            if (isScience) {
                // ── Science student — ALL faculties open ──────
                if (scienceOpt) {
                    scienceOpt.disabled    = false;
                    scienceOpt.textContent = scienceOpt.textContent
                        .replace(' (Science students only)', '');
                }

                const msg     = document.createElement('div');
                msg.id        = 'scienceGroupWarning';
                msg.className = 'alert alert-success py-2 mt-2';
                msg.innerHTML = `
                    <i class="bi bi-check-circle-fill"></i>
                    HSC group: <strong>${group}</strong> —
                    You are eligible to apply for
                    <strong>all faculties</strong>
                    including Science &amp; Engineering.`;
                if (wrapper) wrapper.appendChild(msg);

            } else {
                // ── Non-science student — BLOCK Science faculty ──
                if (scienceOpt) {
                    scienceOpt.disabled    = true;
                    scienceOpt.textContent = 'Science and Engineering (Science students only)';
                }

                // If science was already selected — forcefully reset
                const fSelect = document.getElementById('facultySelect');
                if (fSelect && parseInt(fSelect.value) === SCIENCE_FACULTY_ID) {
                    fSelect.value    = '';
                    currentFacultyId = null;
                    selectedDepts    = [];
                    updatePreferenceDisplay();
                    updateHiddenInputs();
                    updateCountBadge();
                    document.getElementById('deptChoiceContainer').innerHTML = `
                        <p class="text-muted small">
                            <i class="bi bi-arrow-up"></i>
                            Select a faculty above to see available departments.
                        </p>`;
                }

                const msg     = document.createElement('div');
                msg.id        = 'scienceGroupWarning';
                msg.className = 'alert alert-warning py-2 mt-2';
                msg.innerHTML = `
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    HSC group: <strong>${group}</strong> —
                    You <strong>cannot apply</strong> to
                    <strong>Science &amp; Engineering</strong> faculty.
                    All other faculties are available to you.`;
                if (wrapper) wrapper.appendChild(msg);
            }
        }

        function removeScienceGroupWarning() {
            const el = document.getElementById('scienceGroupWarning');
            if (el) el.remove();

            // Re-enable science option when warning is cleared
            const scienceOpt = document.querySelector(
                `#facultySelect option[value="${SCIENCE_FACULTY_ID}"]`
            );
            if (scienceOpt) {
                scienceOpt.disabled    = false;
                scienceOpt.textContent = scienceOpt.textContent
                    .replace(' (Science students only)', '');
            }
        }


        // ════════════════════════════════════════════════════════
        // DYNAMIC SUBJECT ROWS
        // ════════════════════════════════════════════════════════

        const addSubjectBtn = document.getElementById('addSubjectRow');
        if (addSubjectBtn) {
            addSubjectBtn.addEventListener('click', function () {
                const container  = document.getElementById('subjectRows');
                const newRow     = document.createElement('div');
                newRow.className = 'row g-2 mb-2 subject-row';
                newRow.innerHTML = `
                    <div class="col-md-6">
                        <input type="text" name="subject_name[]"
                               class="form-control" placeholder="Subject name">
                    </div>
                    <div class="col-md-4">
                        <input type="number" name="subject_mark[]"
                               class="form-control" step="0.01" min="0"
                               placeholder="Marks obtained">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger w-100 remove-row">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>`;
                container.appendChild(newRow);
                updateRemoveButtons();
            });
        }

        const subjectRowsContainer = document.getElementById('subjectRows');
        if (subjectRowsContainer) {
            subjectRowsContainer.addEventListener('click', function (e) {
                if (e.target.closest('.remove-row')) {
                    e.target.closest('.subject-row').remove();
                    updateRemoveButtons();
                }
            });
        }

        function updateRemoveButtons() {
            const rows = document.querySelectorAll('.subject-row');
            rows.forEach(row => {
                row.querySelector('.remove-row').style.display =
                    rows.length > 1 ? 'block' : 'none';
            });
        }


        // ════════════════════════════════════════════════════════
        // DEPARTMENT PREFERENCES
        // ════════════════════════════════════════════════════════

        const SCIENCE_FACULTY_ID = 1;
        const MAX_DEPT_CHOICES   = 5;

        let selectedDepts    = [];
        let currentFacultyId = null;
        let savedDepts       = [];

        const facultySelect = document.getElementById('facultySelect');
        if (facultySelect) {
            facultySelect.addEventListener('change', function () {
                const faculty_id = parseInt(this.value);
                const container  = document.getElementById('deptChoiceContainer');

                // ── Cleared ───────────────────────────────────
                if (!faculty_id) {
                    currentFacultyId = null;
                    selectedDepts    = [];
                    updatePreferenceDisplay();
                    updateHiddenInputs();
                    updateCountBadge();
                    container.innerHTML = `
                        <p class="text-muted small">
                            <i class="bi bi-arrow-up"></i>
                            Select a faculty above to see available departments.
                        </p>`;
                    return;
                }

                // ── Same faculty reselected — do nothing ──────
                if (faculty_id === currentFacultyId) return;

                // ── Science & Engineering faculty ─────────────
                if (faculty_id === SCIENCE_FACULTY_ID) {

                    // Non-science — hard block (option is disabled but
                    // guard here too in case of manual DOM manipulation)
                    if (fetchedHscGroup &&
                        !fetchedHscGroup.toLowerCase().includes('science')) {

                        this.value = currentFacultyId || '';
                        container.innerHTML = `
                            <div class="alert alert-danger py-2 mb-0">
                                <i class="bi bi-x-circle-fill"></i>
                                <strong>Not eligible.</strong>
                                Your HSC group is <strong>${fetchedHscGroup}</strong>.
                                Only Science students can apply to
                                Science &amp; Engineering faculty.
                            </div>`;
                        setTimeout(() => {
                            if (currentFacultyId) {
                                renderDepartments(currentFacultyId, container);
                                setTimeout(() => {
                                    rebadgeAll(container);
                                    updatePreferenceDisplay();
                                    updateHiddenInputs();
                                    updateCountBadge();
                                }, 0);
                            } else {
                                container.innerHTML = `
                                    <p class="text-muted small">
                                        <i class="bi bi-arrow-up"></i>
                                        Select a faculty above to see available departments.
                                    </p>`;
                            }
                        }, 2500);
                        return;
                    }

                    // Confirmed Science from EduBoard — go straight in
                    if (fetchedHscGroup &&
                        fetchedHscGroup.toLowerCase().includes('science')) {
                        if (selectedDepts.length > 0) {
                            savedDepts = [...selectedDepts];
                            showFacultyChangeWarning(container, faculty_id);
                        } else {
                            commitFacultyChange(faculty_id, container);
                        }
                        return;
                    }

                    // HSC not yet fetched — manual confirmation
                    savedDepts = [...selectedDepts];
                    container.innerHTML = `
                        <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
                            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
                            <div>
                                <strong>Science &amp; Engineering Faculty</strong><br>
                                This faculty is <strong>only available for Science
                                background students</strong> (HSC Science group).<br>
                                <small class="text-muted">
                                    Fetch your HSC data first for automatic validation,
                                    or confirm manually below.
                                </small>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" id="confirmScienceBtn"
                                            class="btn btn-warning btn-sm fw-bold">
                                        <i class="bi bi-check-circle"></i>
                                        I am a Science student — Show Departments
                                    </button>
                                    <button type="button" id="cancelScienceBtn"
                                            class="btn btn-outline-secondary btn-sm">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>`;

                    document.getElementById('confirmScienceBtn')
                        .addEventListener('click', function () {
                            currentFacultyId = faculty_id;
                            selectedDepts    = [];
                            savedDepts       = [];
                            updatePreferenceDisplay();
                            updateHiddenInputs();
                            updateCountBadge();
                            renderDepartments(faculty_id, container);
                        });

                    document.getElementById('cancelScienceBtn')
                        .addEventListener('click', function () {
                            facultySelect.value = currentFacultyId || '';
                            selectedDepts       = [...savedDepts];
                            savedDepts          = [];

                            if (currentFacultyId) {
                                renderDepartments(currentFacultyId, container);
                                setTimeout(() => {
                                    rebadgeAll(container);
                                    updatePreferenceDisplay();
                                    updateHiddenInputs();
                                    updateCountBadge();
                                }, 0);
                            } else {
                                container.innerHTML = `
                                    <p class="text-muted small">
                                        <i class="bi bi-arrow-up"></i>
                                        Select a faculty above to see available departments.
                                    </p>`;
                            }
                        });

                    return;
                }

                // ── All other faculties — warn if clearing choices ─
                if (selectedDepts.length > 0 && faculty_id !== currentFacultyId) {
                    savedDepts = [...selectedDepts];
                    showFacultyChangeWarning(container, faculty_id);
                    return;
                }

                commitFacultyChange(faculty_id, container);
            });
        }


        // ── Commit faculty change ─────────────────────────────
        function commitFacultyChange(faculty_id, container) {
            currentFacultyId = faculty_id;
            selectedDepts    = [];
            savedDepts       = [];
            updatePreferenceDisplay();
            updateHiddenInputs();
            updateCountBadge();
            renderDepartments(faculty_id, container);
        }


        // ── Faculty change warning ────────────────────────────
        function showFacultyChangeWarning(container, new_faculty_id) {
            container.innerHTML = `
                <div class="alert alert-danger d-flex align-items-start gap-2 mb-0">
                    <i class="bi bi-exclamation-circle-fill fs-5 mt-1 flex-shrink-0"></i>
                    <div>
                        <strong>Change Faculty?</strong><br>
                        You have <strong>${savedDepts.length} department(s)</strong>
                        selected. Changing faculty will
                        <strong>clear all your choices</strong>.
                        <div class="mt-2 d-flex gap-2">
                            <button type="button" id="confirmFacultyChange"
                                    class="btn btn-danger btn-sm fw-bold">
                                <i class="bi bi-arrow-repeat"></i>
                                Yes, Change &amp; Clear Choices
                            </button>
                            <button type="button" id="cancelFacultyChange"
                                    class="btn btn-outline-secondary btn-sm">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>`;

            document.getElementById('confirmFacultyChange')
                .addEventListener('click', function () {
                    commitFacultyChange(new_faculty_id, container);
                });

            document.getElementById('cancelFacultyChange')
                .addEventListener('click', function () {
                    facultySelect.value = currentFacultyId || '';
                    selectedDepts       = [...savedDepts];
                    savedDepts          = [];

                    if (currentFacultyId) {
                        renderDepartments(currentFacultyId, container);
                        setTimeout(() => {
                            rebadgeAll(container);
                            updatePreferenceDisplay();
                            updateHiddenInputs();
                            updateCountBadge();
                        }, 0);
                    } else {
                        container.innerHTML = `
                            <p class="text-muted small">
                                <i class="bi bi-arrow-up"></i>
                                Select a faculty above to see available departments.
                            </p>`;
                    }
                });
        }


        // ── Render department picker ──────────────────────────
        function renderDepartments(faculty_id, container) {
            const filtered = allDepts.filter(d => d.faculty_id == faculty_id);

            if (filtered.length === 0) {
                container.innerHTML = `
                    <p class="text-muted small">
                        No departments found for selected faculty.
                    </p>`;
                return;
            }

            let html = `
                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-info-circle-fill"></i>
                    Click departments to add them in <strong>preferred order</strong>.
                    You may select up to <strong>${MAX_DEPT_CHOICES}</strong>.
                    Click again to remove.
                </div>
                <div class="row g-2" id="deptPickerList">`;

            filtered.forEach(dept => {
                html += `
                    <div class="col-md-6">
                        <div class="dept-option border rounded p-2
                                    d-flex align-items-center justify-content-between"
                             data-dept-id="${dept.dept_id}"
                             data-dept-name="${dept.dept_name}"
                             style="cursor:pointer; transition: all 0.15s;">
                            <span class="dept-label fw-semibold small">
                                ${dept.dept_name}
                            </span>
                            <span class="badge bg-secondary dept-badge ms-2">+</span>
                        </div>
                    </div>`;
            });

            html += `</div>`;
            container.innerHTML = html;

            container.querySelectorAll('.dept-option').forEach(el => {
                el.addEventListener('click', function () {
                    const dept_id   = parseInt(this.dataset.deptId);
                    const dept_name = this.dataset.deptName;
                    const idx       = selectedDepts.findIndex(d => d.id === dept_id);

                    if (idx !== -1) {
                        selectedDepts.splice(idx, 1);
                        hideDeptLimitWarning(container);
                    } else {
                        if (selectedDepts.length >= MAX_DEPT_CHOICES) {
                            showDeptLimitWarning(container);
                            return;
                        }
                        selectedDepts.push({ id: dept_id, name: dept_name });
                        hideDeptLimitWarning(container);
                    }

                    rebadgeAll(container);
                    updatePreferenceDisplay();
                    updateHiddenInputs();
                    updateCountBadge();
                });
            });
        }
        

        // ── Re-number badges ──────────────────────────────────
        function rebadgeAll(container) {
            container.querySelectorAll('.dept-option').forEach(el => {
                const dept_id = parseInt(el.dataset.deptId);
                const badge   = el.querySelector('.dept-badge');
                const label   = el.querySelector('.dept-label');
                const idx     = selectedDepts.findIndex(d => d.id === dept_id);

                if (idx !== -1) {
                    el.classList.add('border-primary', 'bg-primary');
                    label.classList.add('text-white');
                    badge.className   = 'badge bg-white text-primary dept-badge ms-2';
                    badge.textContent = idx + 1;
                } else {
                    el.classList.remove('border-primary', 'bg-primary');
                    label.classList.remove('text-white');
                    badge.className   = 'badge bg-secondary dept-badge ms-2';
                    badge.textContent = '+';
                }
            });
        }


        // ── Preference display ────────────────────────────────
        function updatePreferenceDisplay() {
            const display = document.getElementById('deptPreferenceDisplay');
            const ol      = document.getElementById('deptOrderedList');
            if (!display || !ol) return;

            if (selectedDepts.length === 0) {
                display.style.display = 'none';
                ol.innerHTML = '';
                return;
            }

            display.style.display = 'block';
            ol.innerHTML = selectedDepts.map((dept, i) => `
                <li class="list-group-item d-flex justify-content-between
                           align-items-center py-2 px-3">
                    <span class="fw-semibold">${dept.name}</span>
                    <span class="badge bg-primary rounded-pill">
                        Choice ${i + 1}
                    </span>
                </li>`
            ).join('');
        }


        // ── Hidden inputs ─────────────────────────────────────
        function updateHiddenInputs() {
            const wrap = document.getElementById('deptHiddenInputs');
            if (!wrap) return;
            wrap.innerHTML = selectedDepts.map(dept =>
                `<input type="hidden" name="dept_choices[]" value="${dept.id}">`
            ).join('');
        }


        // ── Count badge ───────────────────────────────────────
        function updateCountBadge() {
            const badge   = document.getElementById('deptCountBadge');
            const countEl = document.getElementById('deptCountText');
            if (!badge || !countEl) return;

            if (selectedDepts.length > 0) {
                badge.style.display = 'block';
                countEl.textContent = selectedDepts.length;
                badge.querySelector('.badge').className =
                    selectedDepts.length >= MAX_DEPT_CHOICES
                        ? 'badge bg-danger fs-6 px-3 py-2'
                        : 'badge bg-primary fs-6 px-3 py-2';
            } else {
                badge.style.display = 'none';
            }
        }


        // ── Dept limit warning ────────────────────────────────
        function showDeptLimitWarning(container) {
            if (container.querySelector('#deptLimitWarn')) return;
            const warn     = document.createElement('div');
            warn.id        = 'deptLimitWarn';
            warn.className = 'alert alert-danger py-2 mt-2';
            warn.innerHTML = `
                <i class="bi bi-slash-circle-fill"></i>
                Maximum <strong>${MAX_DEPT_CHOICES} departments</strong> allowed.
                Remove one before adding another.`;
            container.appendChild(warn);
        }

        function hideDeptLimitWarning(container) {
            const warn = container.querySelector('#deptLimitWarn');
            if (warn) warn.remove();
        }


        // ════════════════════════════════════════════════════════
        // HELPERS
        // ════════════════════════════════════════════════════════

        function setField(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            if (value === undefined || value === null || value === '') return;
            el.value = value;
        }
        function showCaptchaLoading(type){

            document
                .getElementById(type+"_captcha_loader")
                .classList.remove("d-none");

            document
                .getElementById(type+"_captcha_image")
                .classList.add("d-none");

        }

        function hideCaptchaLoading(type){

            document
                .getElementById(type+"_captcha_loader")
                .classList.add("d-none");

            document
                .getElementById(type+"_captcha_image")
                .classList.remove("d-none");

        }

        async function loadCaptcha(type) {

            showCaptchaLoading(type);

            try {

                const response = await fetch('/jkkniu-admission/api/eduboard_session.php', {
                    method: 'POST'
                });

                const data = await response.json();
                

                if (!data.success) {
                    throw new Error(data.message);
                    
                }

                document.getElementById(type + "_session_id").value = data.session_id;

                const img = document.getElementById(type + "_captcha_image");

                img.onload = function () {
                    hideCaptchaLoading(type);
                };

                img.src = "data:image/jpeg;base64," + data.image;

            } catch (e) {

                hideCaptchaLoading(type);

                alert("Failed to load CAPTCHA.\n\n" + e.message);

            }

        }
        window.loadCaptcha = loadCaptcha;
        function setDateField(id, value) {
            const el = document.getElementById(id);
            if (!el || !value) return;

            if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                el.value = value;
                return;
            }

            const sep   = value.includes('/') ? '/' : '-';
            const parts = value.split(sep);

            if (parts.length === 3) {
                let day, month, year;
                if (parts[0].length === 4) {
                    [year, month, day] = parts;
                } else {
                    [day, month, year] = parts;
                }
                const formatted =
                    `${year}-${month.padStart(2,'0')}-${day.padStart(2,'0')}`;
                if (/^\d{4}-\d{2}-\d{2}$/.test(formatted)) {
                    el.value = formatted;
                } else {
                    console.warn('Could not parse date:', value);
                }
            }
        }
       // Load CAPTCHAs when the page opens
        loadCaptcha("hsc");
        loadCaptcha("ssc");

        // Refresh buttons
        document
            .getElementById("refresh_hsc_captcha")
            ?.addEventListener("click", function () {
                loadCaptcha("hsc");
            });

        document
            .getElementById("refresh_ssc_captcha")
            ?.addEventListener("click", function () {
                loadCaptcha("ssc");
            });

    }); // end DOMContentLoaded

})(); // end IIFE
