'use strict';

// Presentation only: never submit a form or change the selected status.
for (const select of document.querySelectorAll('select[name^="status["]')) {
    select.classList.add('attendance-select');
    const paint = () => { select.dataset.status = select.value; };
    select.addEventListener('change', paint);
    window.addEventListener('pageshow', paint);
    paint();
}

const studentSearch = document.getElementById('student-search');
if (studentSearch) {
    const rows = [...document.querySelectorAll('[data-student-row]')];
    const normalize = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLocaleLowerCase('vi');
    studentSearch.addEventListener('input', () => {
        const term = normalize(studentSearch.value.trim());
        for (const row of rows) row.hidden = !normalize((row.querySelector('[data-student-search]') || row.querySelector('summary')).textContent).includes(term);
        const empty = document.getElementById('student-search-empty');
        if (empty) empty.hidden = rows.some((row) => !row.hidden);
    });
}

for (const trigger of document.querySelectorAll('[data-edit-student]')) {
    trigger.addEventListener('click', (event) => {
        const details = document.getElementById(trigger.dataset.editStudent);
        if (!details) return;
        event.preventDefault();
        details.open = true;
        details.querySelector('input:not([type="hidden"])').focus({preventScroll: true});
        details.scrollIntoView({block: 'nearest', behavior: 'auto'});
    });
}

const deleteDialog = document.getElementById('delete-confirmation');
if (deleteDialog && typeof deleteDialog.showModal === 'function') {
    const form = document.getElementById('delete-student-form');
    for (const trigger of document.querySelectorAll('[data-delete-student]')) {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            deleteDialog.querySelector('[data-delete-name]').textContent = trigger.dataset.studentName;
            deleteDialog.querySelector('[data-delete-code]').textContent = trigger.dataset.studentCode;
            form.elements.id.value = trigger.dataset.deleteStudent;
            form.elements.confirmation.value = trigger.dataset.confirmation;
            const blocked = trigger.dataset.hasAttendance === '1';
            deleteDialog.querySelector('[data-delete-explanation]').textContent = blocked
                ? 'Không thể xóa: sinh viên đã có dữ liệu điểm danh, kể cả Chưa điểm danh. Hãy dùng Ngừng theo dõi để giữ nguyên lịch sử và thống kê.'
                : 'Hồ sơ sẽ bị xóa vĩnh viễn. Hệ thống chỉ cho phép xóa nếu sinh viên chưa có dữ liệu điểm danh hoặc quan hệ nghiệp vụ cần bảo toàn.';
            form.querySelector('button[type="submit"]').disabled = blocked;
            deleteDialog.showModal();
            deleteDialog.querySelector('[data-close-delete]').focus();
        });
    }
    deleteDialog.querySelector('[data-close-delete]').addEventListener('click', (event) => {
        event.preventDefault();
        deleteDialog.close();
    });
    if (deleteDialog.open) {
        deleteDialog.close();
        deleteDialog.showModal();
        deleteDialog.querySelector('[data-close-delete]').focus();
    }
}

for (const container of document.querySelectorAll('.table-scroll, .student-list')) {
    container.tabIndex = 0;
    if (!container.getAttribute('aria-label')) container.setAttribute('aria-label', 'Bảng dữ liệu, có thể cuộn ngang');
}
