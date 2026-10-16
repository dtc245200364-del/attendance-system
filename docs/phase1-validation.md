# Biên bản kiểm tra Giai đoạn 1

> Đây là biên bản lần xây dựng ban đầu. Kết quả nghiệm thu mới nhất nằm tại [phase1-acceptance.md](phase1-acceptance.md), với 118 kiểm tra đạt và ảnh chụp Chrome thật.

Ngày thực hiện: **16/10/2026, giờ Việt Nam**. Đây là kết quả chạy lệnh thực tế, không phải ảnh minh chứng hoặc báo cáo tổng kết toàn bộ đề.

## Hiện trạng trước khi sửa

- Toàn bộ 18 file khung tìm được đều có kích thước 0 byte; các thư mục docs, tests và grafana chưa có nội dung.
- Không có AGENTS.md hoặc thư mục `.git` riêng trong workspace. Lệnh `git status` và `git -C ... status` thất bại với `fatal: cannot change to 'C:/Users/Admin'`; thư mục cha của người dùng có `.git`. Không khởi tạo Git, không thay cấu hình Git toàn cục, không commit/push.
- Máy có Docker Compose v5.5.1, không tìm thấy PHP/MySQL CLI trên host; kiểm thử bằng PHP/MySQL thật trong container mới.
- Các container cũ `attendance-web`, `attendance-phpmyadmin`, `attendance-db`, `attendance-system-mysql-1`, `attendance-system-nginx-1` và các container `restaurant-*` đã tồn tại. Nginx cũ đã exited và mailpit nhà hàng đã restart trước khi làm; không can thiệp.
- Cổng 8085/8086 thuộc bộ cũ; chọn 18085/18086 sau khi kiểm tra listener.

## Kết quả cuối

Chạy `scripts/test.ps1` sau lần build cuối:

```text
No syntax errors detected in /var/www/html/history.php
No syntax errors detected in /var/www/html/classes.php
No syntax errors detected in /var/www/html/students.php
No syntax errors detected in /var/www/html/index.php
No syntax errors detected in /var/www/html/includes/bootstrap.php
No syntax errors detected in /var/www/html/includes/db.php
No syntax errors detected in /var/www/html/statistics.php
No syntax errors detected in /var/www/html/attendance.php
RESULT: 52 assertions passed
Test fixtures cleaned.
```

Các nhóm kiểm tra đã chạy:

| Nhóm | Kết quả |
|---|---|
| Compose config, build và khởi động | Thành công |
| HTTP các trang và 2 file CSS | 200 |
| Thêm/sửa lớp; xóa lớp trống; chặn xóa lớp có sinh viên | Đạt |
| Thêm/sửa sinh viên; ngừng/khôi phục; chặn chuyển lớp có lịch sử | Đạt |
| Validation mã, email, ngày không có thật, ID dạng mảng | Đạt |
| Tạo buổi; chụp danh sách; bỏ qua sinh viên ngừng theo dõi | Đạt |
| Lưu có mặt/vắng/muộn; lưu lại không nhân bản | Đạt |
| Chặn thiếu danh sách, trạng thái sai; rollback | Đạt |
| Unique lớp, sinh viên, buổi; PK kép điểm danh; FK | Đạt |
| Bộ lọc lớp/sinh viên/ngày và tỷ lệ tham dự | Đạt |
| CSRF, SQL injection qua bộ lọc, escape stored XSS | Đạt |
| Security headers và chặn CREATE TABLE bằng user ứng dụng | Đạt |

Kiểm tra độc lập từ Windows bằng `Invoke-WebRequest`: website **HTTP 200**, phpMyAdmin **HTTP 200**. `docker compose exec -T web id`: **uid=33(www-data), gid=33(www-data)**. MySQL ở trạng thái **healthy**; 3 container Giai đoạn 1 đang chạy. Danh sách container sau kiểm thử vẫn chứa đầy đủ container cũ, với trạng thái tương ứng hiện trạng ban đầu.

## Lỗi đã tìm và sửa

1. ID buổi dạng mảng được phát hiện sau khi đã xuất HTML khiến HTTP còn 200. Đưa validation lên trước render; kiểm thử cuối nhận 422.
2. REVOKE theo tên schema chứa `_` không trùng với grant đã escape của image MySQL, dẫn đến init dừng ở bước cấp quyền, trong khi schema đã được tạo. Đổi sang thu hồi toàn bộ quyền của riêng `attendance_app`, sau đó cấp bốn quyền DML trên schema đã escape trong `database/permissions.sql`. Áp dụng sửa trực tiếp trên DB mới, không xóa volume hoặc database. Lượt cuối đã xác nhận tài khoản không tạo được bảng.
3. Bảng `forbidden_test` do lần kiểm thử quyền đầu tiên tạo đã được dọn bằng script cleanup riêng; dữ liệu mẫu của từng lượt test được xóa theo đúng ID ngẫu nhiên. Không xóa dữ liệu hay tài nguyên tồn tại trước nhiệm vụ.

## File đã tạo / điền nội dung

- `app/index.php`, `classes.php`, `students.php`, `attendance.php`, `history.php`, `statistics.php`.
- `app/includes/db.php`, `bootstrap.php`; `app/assets/style.css`, `base.css`.
- `database/init.sql`, `permissions.sql`.
- `Dockerfile`, `docker-compose.yml`, `docker/php.ini`, `.dockerignore`.
- `.env.example`, `.env` (mật khẩu ngẫu nhiên cục bộ, không in ra và không đưa Git), `.gitignore`.
- `scripts/setup-env.ps1`, `test.ps1`, `apply-permissions.sh`, `cleanup-test-artifact.sql`, `cleanup-test-artifact.sh`.
- `tests/integration.php`, `lint.php`; `README.md`; biên bản này.

Các file khung `nginx/default.conf`, `prometheus/prometheus.yml`, `loki/config.yml`, `promtail/config.yml` vẫn rỗng và không được đưa vào Compose Giai đoạn 1.

## Giới hạn còn lại

- Chưa kiểm tra trực quan responsive trên trình duyệt hoặc tạo screenshot; HTTP test không chứng minh bố cục hiển thị chính xác trên mọi thiết bị.
- Chưa có đăng nhập, vai trò người dùng, HTTPS, xuất Excel hoặc nhật ký ai đã sửa điểm danh. Hiện giới hạn truy cập host bằng loopback.
- Chưa kiểm thử tải, nhiều người ghi đồng thời hoặc tình huống MySQL ngừng giữa giao dịch.
- Database giữ danh sách thành viên theo buổi; họ tên/mã được đọc từ hồ sơ hiện tại, không lưu bản sao tên cũ khi đổi hồ sơ.
- Chưa hoàn thành hardening toàn stack, Nginx, monitoring/logging, GitHub, 3 commit hoặc báo cáo 10 trang. Những phần này ngoài Giai đoạn 1.
- Không ghi nhận lỗi chức năng trong phạm vi 52 kiểm tra cuối; các giới hạn trên vẫn cần được xử lý/kiểm chứng theo yêu cầu của giai đoạn tiếp theo.
