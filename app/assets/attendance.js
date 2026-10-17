'use strict';
const attendanceForm = document.getElementById('attendance-mark');
if (attendanceForm) {
    attendanceForm.querySelector('button').disabled = false;
    attendanceForm.addEventListener('submit', () => {
        const statuses = {};
        const selects = attendanceForm.querySelectorAll('select[name^="status["]');
        for (const select of selects) statuses[select.name.slice(7, -1)] = select.value;
        let payload = attendanceForm.querySelector('input[name="status_payload"]');
        if (!payload) {
            payload = document.createElement('input');
            payload.type = 'hidden';
            payload.name = 'status_payload';
            attendanceForm.appendChild(payload);
        }
        payload.value = JSON.stringify(statuses);
        // A single field avoids max_input_vars truncating a large roster.
        for (const select of selects) select.disabled = true;
    });
    // Restore controls when returning through browser history.
    window.addEventListener('pageshow', () => {
        for (const select of attendanceForm.querySelectorAll('select')) select.disabled = false;
    });
}
