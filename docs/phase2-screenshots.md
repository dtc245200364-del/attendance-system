# Tự chụp minh chứng thật — Giai đoạn 2

Thực hiện trên stack đang chạy tại `C:\Users\Admin\OneDrive\Desktop\attendance-system`. Không chụp `.env`, mật khẩu, cookie, CSRF token hoặc toàn bộ `docker compose config` vì output có thể chứa secrets. Nếu nhập dữ liệu minh họa, đặt tên rõ **kiểm thử**, không mô tả là dữ liệu sinh viên thật.

1. Mở PowerShell tại workspace, chạy `Get-Location` và `docker compose ps`. Chụp cửa sổ có đúng đường dẫn, Nginx/web/DB healthy, Nginx `127.0.0.1:18087`, phpMyAdmin `127.0.0.1:18086`, web không có host port. Không chạy lệnh dừng/xóa tài nguyên để chụp lỗi.
2. Mở `http://127.0.0.1:18087/` trên trình duyệt. Chụp toàn cửa sổ, giữ thanh URL để chứng minh truy cập qua proxy. Tạo lớp, sinh viên và buổi có nhãn kiểm thử nếu cần dữ liệu để minh họa; không chỉnh sửa dữ liệu cũ.
3. Ghi ba trạng thái có mặt/vắng/đi muộn, bấm lưu, mở lại cùng buổi. Chụp danh sách đã lưu và lịch sử/thống kê tương ứng. Không tạo nhầm buổi trùng lớp/ngày giờ với dữ liệu đang có.
4. Mở DevTools → Network, tải lại trang, chọn request document → Headers → Response Headers. Chụp bốn header `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`; tránh phần Request Headers/Cookie.
5. Dùng DevTools Device Toolbar (Ctrl+Shift+M), đặt chiều rộng 390px. Chụp form và bảng; cuộn ngang trong bảng tới cột trạng thái. Chụp thêm ở 768px hoặc desktop để so sánh.
6. Chạy `curl.exe -I http://127.0.0.1:18087/` và `curl.exe http://127.0.0.1:18087/healthz`, chụp status/header và `{"status":"ok"}`. Khi chụp terminal không để lộ thông tin mật từ các lệnh trước.
7. Mở `http://127.0.0.1:18086/`, đăng nhập bằng tài khoản ứng dụng. Chụp tên database/bảng sau đăng nhập; không chụp màn hình nhập mật khẩu, cookie hoặc thông tin nhạy cảm trong dữ liệu thật.
8. Chạy lần lượt các script test trong README. Chụp dòng tổng kết thực tế (72 proxy nghiệp vụ, 72 regression nội bộ, 51 proxy + 1 peer không tin cậy, 17 hạ tầng, 29 trình duyệt). Nếu test lỗi, lưu lỗi và sửa trước; không chỉnh ảnh/output thành pass. `proxy-test.ps1` mô phỏng lỗi trong listener nội bộ, không tắt backend/DB đang chạy.

Lưu ảnh thủ công mới vào thư mục riêng như `docs/evidence/phase2-manual/`, đặt tên theo nội dung và ngày chụp. Dùng Win+Shift+S hoặc công cụ capture screenshot của trình duyệt. Không ghép/chỉnh trạng thái dịch vụ hoặc con số test. Ảnh có dữ liệu thật cần được chủ dữ liệu cho phép trước khi đưa vào báo cáo/GitHub.

Các ảnh tự động đã có ở `docs/evidence/phase2/` là ảnh Chrome chụp thật bằng DevTools, không có thanh địa chỉ; chúng bổ trợ kiểm thử responsive, không thay thế ảnh người dùng chụp có URL. Hai bộ dữ liệu kiểm thử xuất hiện trong ảnh gồm bộ bảo toàn qua recreate và bộ trình duyệt; cả hai đã được dọn đúng phạm vi sau kiểm thử.
