# Tự chụp minh chứng Giai đoạn 4 trên máy thật

Không có ảnh AI hoặc ảnh minh chứng giả. Các bước dưới đây dành cho người dùng chụp trực tiếp sau khi kiểm thử. Chỉ tạo thư mục `docs/evidence/phase4/` khi thực sự lưu ảnh. Không chụp trang đăng nhập với mật khẩu, file `.env*`, `secrets/`, Authorization header hoặc raw Docker log.

1. Mở Docker Desktop → Containers, lọc `attendance-phase1-fresh`. Chụp các service Loki, Promtail, PHP, Nginx và DB đang Running/Healthy; không mở phần biến môi trường. Tên gợi ý `01-containers.png`.
2. Mở http://127.0.0.1:18087/classes.php và chụp website thật, tránh hiển thị dữ liệu cá nhân nếu bổ sung dữ liệu sau này. `02-website.png`.
3. Đăng nhập http://127.0.0.1:13001 bằng tài khoản cục bộ đã có (không chụp mật khẩu). Connections → Data sources → Loki → Save & test: chụp kết quả kết nối thành công. Giữ datasource Prometheus mặc định. `03-loki-datasource.png`.
4. Mở http://127.0.0.1:13001/d/attendance-logs → Last 15 minutes → Refresh. Chụp dashboard **Attendance System Logs** có bốn panel và bộ lọc Service/Level. Nếu log cũ ngoài cửa sổ, truy cập lại website và tạo 404 mới, không dùng dữ liệu mẫu. `04-logs-dashboard.png`.
5. Trong tab khác mở http://127.0.0.1:18087/phase4-log-proof để tạo HTTP 404 thật. Explore → Loki → Code, nhập `{job="attendance",service="nginx"} | json | status = 404`, Run query. Chụp truy vấn và dòng log có `route="proof"`, status 404 và request ID. Nếu cần đối chiếu chính xác, DevTools → Network của request 404 → Response headers → X-Request-ID; không chụp Cookie/Request headers. `05-real-404.png`.
6. Explore: chạy `{job="attendance",service="nginx"}` rồi `{job="attendance",service="php"}`; chụp log đã lọc (không có IP, query, cookie hoặc form). Truy cập `/classes.php` trước để tạo sự kiện PHP mới. `06-nginx-logs.png`, `07-php-logs.png`.
7. Explore: chạy `sum by (service) (count_over_time({job="attendance"}[5m]))`, chụp kết quả thực tế; MySQL có thể không có series trong 5 phút nếu không phát sinh sự kiện. Không tự thêm số liệu hoặc restart DB để đủ series. `08-log-count.png`.
8. Để xem mã log MySQL thật: `{job="attendance",service="mysql"}`, chọn khoảng thời gian bao gồm lúc DB khởi động; chụp các mã `MY-xxxxxx` đã lọc. Nếu log đã quá retention, ghi nhận không có dữ liệu, không giả ảnh. `09-mysql-server-codes.png`.
9. Mở http://127.0.0.1:19090/targets, chụp 4 targets UP; mở http://127.0.0.1:13001/d/attendance-system chụp dashboard monitoring 9 panel có dữ liệu. `10-targets.png`, `11-monitoring-regression.png`.
10. Terminal tại đúng workspace: chạy `docker compose config --quiet`, `docker compose ps`, `scripts/logging-test.ps1` và các lệnh regression trong README. Chụp dòng RESULT của lần chạy thật; không mở full `docker compose config` vì có thể lộ mật khẩu. `12-test-results.png`.

Kiểm tra từng ảnh trước khi thêm Git: ảnh phải phản ánh đúng thời điểm chạy, đọc được truy vấn/trạng thái, không chứa thông tin đăng nhập hoặc dữ liệu sinh viên. Hướng dẫn này không thay thế ảnh người dùng tự chụp.
