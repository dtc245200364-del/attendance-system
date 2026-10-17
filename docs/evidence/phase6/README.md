# Ảnh kiểm thử Giai đoạn 6

Ảnh chụp Chrome headless thật bằng `scripts/browser-test.ps1`, truy cập stack riêng tại `http://127.0.0.1:18097`. Dữ liệu trong ảnh là fixture kiểm thử có tiền tố ngẫu nhiên, không phải dữ liệu điểm danh gốc. Fixture đã được dọn khỏi database test sau khi chạy.

Lượt chạy thành công: **29 kiểm tra đạt**, gồm layout 390/768/1440px, JavaScript gửi form và lưu ba trạng thái, CSRF, lỗi validation và lịch sử rỗng. Không chỉnh sửa, ghép hoặc tạo ảnh giả.

- `attendance-390.png`, `attendance-390-scrolled.png`, `attendance-768.png`, `attendance-1440.png`: form điểm danh có ID buổi.
- `statistics-390.png`, `statistics-768.png`, `statistics-1440.png`: giao diện thống kê.
- `validation-error.png`, `empty-history.png`: thông báo lỗi và bộ lọc rỗng.

Chưa có ảnh riêng của HTTP 409/form cũ, cảnh báo buổi tương tự và lưu 1.101 sinh viên. Các tình huống này được xác nhận bằng bộ kiểm thử tự động, không suy diễn từ các ảnh layout trên.
