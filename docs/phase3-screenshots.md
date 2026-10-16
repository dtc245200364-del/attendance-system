# Minh chứng thật — Giai đoạn 3

Chụp trên hệ thống đang chạy, không sửa ảnh để thay số liệu hoặc trạng thái. Không chụp `.env`, `.env.grafana`, `secrets/`, cookie/Authorization header hoặc `docker compose config` đầy đủ vì có thể chứa mật khẩu. Dữ liệu test được ghi nhãn kiểm thử; không giả làm sinh viên thật.

1. Trong PowerShell tại workspace, chạy `Get-Location` và `docker compose ps`. Chụp đường dẫn, các service giám sát, hai UI bind loopback và website 18087/phpMyAdmin 18086. Trong Docker Desktop mở đúng project `attendance-phase1-fresh`, chụp danh sách container và trạng thái, không thao tác Stop/Delete.
2. Mở **http://127.0.0.1:19090/targets** hoặc menu Status → Target health tùy phiên bản. Chụp toàn bộ jobs và trạng thái thực. `up=1` chỉ chứng minh scrape endpoint; cần chụp thêm `nginx_up` và `mysql_up` để chứng minh exporter kết nối được dịch vụ.
3. Mở **http://127.0.0.1:13001**, đăng nhập bằng user trong `.env.grafana`. Không chụp màn hình nhập mật khẩu. Mở Dashboards → Attendance → **Attendance System Monitoring**, chọn khoảng **Last 15 minutes**, refresh 15s. Giữ thanh URL và chú thích panel trong ảnh.
4. Chụp nhóm CPU/RAM/network theo service và nhóm Nginx requests/MySQL connections. Các đường bằng 0 khi nhàn rỗi vẫn là số liệu thật nếu có series; `No data` cần được điều tra, không thay bằng giá trị tự tạo.
5. Tạo truy cập bằng `1..40 | ForEach-Object { Invoke-WebRequest -UseBasicParsing http://127.0.0.1:18087/ | Out-Null }`. Chờ ít nhất hai scrape (khoảng 30–45s), refresh và chụp counter/rate thay đổi. Đây là traffic kiểm thử thật, không phải tải người dùng thật.
6. Trên Prometheus truy vấn `nginx_http_requests_total`, `mysql_global_status_connections`, `mysql_global_status_threads_connected`. Đối với container, dùng bộ lọc `container_label_com_docker_compose_project="attendance-phase1-fresh"`; không dùng số liệu tổng Docker VM để mô tả một container cụ thể.
7. Trong Grafana mở Connections → Data sources → Prometheus, chụp trạng thái kiểm tra datasource hoặc query inspector đã trả dữ liệu. Không chụp request có Basic Authorization hoặc cookie đăng nhập.
8. Chạy `scripts/monitoring-test.ps1`, `scripts/test.ps1` và `scripts/acceptance-check.ps1`; chụp kết quả thực tế. Nếu một bài lỗi, ghi nhận lỗi và xử lý, không chỉnh output/ảnh thành pass. Đối chiếu kết quả với biên bản Giai đoạn 3.

Kết quả nghiệm thu sau khi chuyển Docker sang D: **54 monitoring + 4 access + 72 nghiệp vụ + 17 hạ tầng = 147 pass/0 fail**. Để chụp đủ nhóm access, chạy thêm `scripts/monitoring-access-test.ps1`. Target health đã xác minh 4 UP/0 DOWN; dashboard có dữ liệu 9/9 panel. Dashboard trực tiếp: http://127.0.0.1:13001/d/attendance-system . Chờ ít nhất hai scrape sau khi service khởi động lại để rate panels có dữ liệu.

Nếu cAdvisor không có metrics từng container trên Docker Desktop, chụp trạng thái thực và ghi giới hạn; không sử dụng đường CPU/RAM của host để khẳng định đã giám sát từng container. Lưu ảnh thủ công vào `docs/evidence/phase3-manual/` với ngày chụp và nội dung rõ ràng. Cần loại thông tin nhạy cảm trước khi được phép đưa ảnh lên GitHub.
