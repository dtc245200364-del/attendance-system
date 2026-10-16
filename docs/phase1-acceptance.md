# Nghiệm thu Giai đoạn 1 — Attendance System

**Ngày: 16/10/2026 (UTC+7). Phạm vi: PHP, MySQL, phpMyAdmin, Docker Compose.** Không triển khai Nginx, Prometheus, Grafana, Loki hoặc Promtail. Không commit/push, không xóa dữ liệu/container/volume cũ.

## Kết luận

Ứng dụng **đạt nghiệm thu chức năng Giai đoạn 1 trong môi trường cục bộ và phạm vi kiểm thử dưới đây**. Không còn lỗi chức năng tái hiện được trong bộ kiểm thử cuối. Mã nguồn đủ làm nội dung Commit 1, nhưng **chưa nên commit ngay trong Git hiện tại**: Git root đang là `C:/Users/Admin`. Cần khởi tạo repository riêng tại workspace, xem lại staging và xác nhận không có `.env` trước khi thực hiện commit theo yêu cầu tiếp theo. Chưa chạy `git init`, `git add`, `git commit` hay `git push`.

## Kết quả thực tế

| Bộ kiểm thử cuối | Đạt | Thất bại |
|---|---:|---:|
| `scripts/test.ps1` — HTTP thật + MySQL thật | 72 | 0 |
| `scripts/browser-test.ps1` — Chrome headless | 29 | 0 |
| `scripts/acceptance-check.ps1` — hạ tầng, Git, secrets, phpMyAdmin | 17 | 0 |
| **Tổng số assertions tự động** | **118** | **0** |

Ngoài 118 assertions: **8/8 file PHP ứng dụng** qua `php -l`, Compose config hợp lệ, image build được, kiểm tra log web sau lượt cuối không tìm thấy PHP Warning/Fatal trong khoảng kiểm tra. Các con số là kết quả lượt chạy cuối, không cộng dồn các lần chạy trước.

Các dòng tổng kết từ output thực tế:

```text
RESULT: 72 assertions passed
Test fixtures cleaned.
RESULT: 29 browser assertions passed
Browser fixtures cleaned.
RESULT: 17 infrastructure/security assertions passed
Git root: C:/Users/Admin
```

Trong quá trình dựng công cụ kiểm thử, Chrome đã có hai lượt không kết nối được DevTools, trước khi có assertions trình duyệt. Đã sửa script PowerShell đọc danh sách tab; lượt cuối chạy đủ 29 assertions. Không tính các lỗi công cụ này thành bài kiểm thử ứng dụng đã đạt.

## Ma trận nghiệm thu

| Hạng mục | Trạng thái | Bằng chứng / giới hạn |
|---|---|---|
| Website | Đạt | HTTP 200 từ host và container; 6 trang chức năng |
| MySQL | Đạt | Healthy, truy vấn bằng PDO, FK/unique/trigger hoạt động |
| phpMyAdmin | Đạt | HTTP 200; đăng nhập bằng `attendance_app`, nhìn thấy database Giai đoạn 1 |
| Lớp học | Đạt | Thêm, sửa, xóa lớp trống; chặn mã trùng và xóa lớp có sinh viên |
| Sinh viên | Đạt | Thêm, sửa, ngừng/khôi phục; giữ lịch sử, chặn chuyển lớp có điểm danh |
| Buổi và điểm danh | Đạt | Chụp danh sách, ba trạng thái, lưu lại không nhân bản, rollback khi dữ liệu sai |
| Lịch sử | Đạt | Lọc lớp/sinh viên/ngày; dữ liệu trống; 56 bản ghi chia hai trang, trang hai 6 dòng |
| Thống kê | Đạt | Đối chiếu tỷ lệ từng sinh viên 100%/0%/100%, bỏ qua chưa ghi |
| Validation | Đạt trong bộ test | Mã, tên rỗng/quá dài, email, ngày sai, ID mảng, ID không tồn tại, khoảng ngày đảo |
| CSRF | Đạt | Thiếu/sai/token dạng mảng bị chặn 403; có thông báo và đường tải lại |
| SQL injection | Đạt trong bộ test | Bộ lọc payload bị chặn; payload trong tên lưu như dữ liệu, bảng vẫn tồn tại |
| XSS | Đạt trong bộ test | Payload script được escape trong HTML; CSP và nosniff có trong response |
| FK/chống trùng | Đạt | FK từ chối tham chiếu sai; mã unique, buổi unique, PK kép điểm danh |
| Nhất quán lớp–sinh viên–buổi | Đạt trong bộ test | Chặn INSERT/UPDATE chéo lớp, chuyển lớp bằng SQL, gửi roster giả qua HTTP |
| Responsive | Đạt trên Chrome kiểm thử | 6 trang × 390/768/1440px không tràn ngang toàn trang; bảng cuộn tới ô trạng thái |
| Trạng thái lỗi/trống | Đạt trong bộ test | Validation có alert; CSRF có phục hồi; khoảng ngày không có dữ liệu hiển thị đúng |
| Secrets/Git | Đạt kiểm tra an toàn, còn việc chuẩn bị repo | `.env` ignore, không tracked, không có commit workspace trong lịch sử truy cập được; không tìm thấy bản sao mật khẩu thật trong source/docs/tests |
| Cô lập Docker | Đạt | Project, volume, network riêng; DB không publish; cổng host 18085/18086 bind loopback |
| Git repository riêng / Commit 1 | Chưa thực hiện | Repository cha ở thư mục người dùng; chưa init/add/commit/push |

## Các lỗi đã sửa trong lần nghiệm thu

1. **Database thiếu kiểm soát khác lớp:** FK riêng cho sinh viên và buổi chỉ bảo đảm hai bản ghi tồn tại. Bổ sung bốn trigger trong `database/consistency.sql` để chặn điểm danh chéo lớp khi thêm/sửa và chặn đổi lớp của sinh viên/buổi đã có danh sách. Áp dụng migration bổ sung trên DB Giai đoạn 1 hiện có; không xóa bảng hoặc dữ liệu. Test kiểm chứng bằng SQL trực tiếp và HTTP.
2. **Báo thành công cho ID không tồn tại:** thao tác sửa/xóa lớp, ngừng/khôi phục sinh viên trước đó có thể UPDATE/DELETE 0 dòng rồi báo đã lưu. Bổ sung kiểm tra tồn tại, trả 422; kiểm thử lớp/sinh viên không tồn tại đã đạt.
3. **Cột họ tên bị bó hẹp:** ảnh Chrome thật cho thấy bảng ép tên xuống nhiều dòng ở kích thước nhỏ. Đặt độ rộng tối thiểu cho bảng/cột, giữ cuộn ngang trong khung; kiểm tra chiều rộng tên và thao tác cuộn đến trạng thái.
4. **Trang lỗi CSRF thiếu giao diện phục hồi:** chuyển sang layout chung, alert và nút tải lại; giữ HTTP 403. Kiểm tra trên trình duyệt đã đạt.
5. **Phòng ngừa cấu hình:** bổ sung ignore `.env.*` nhưng giữ `.env.example` trong cả Git và Docker context; rollback tập trung khi validation phát sinh trong transaction. Đây là cải thiện phòng ngừa, không phải lỗi mất dữ liệu đã quan sát.

## File thay đổi / bổ sung trong nghiệm thu

- `app/includes/bootstrap.php`, `app/classes.php`, `app/students.php`, `app/assets/style.css`.
- `database/consistency.sql`, `docker-compose.yml`.
- `tests/integration.php`, `tests/browser-fixture.php`.
- `scripts/apply-consistency.sh`, `scripts/browser-test.ps1`, `scripts/acceptance-check.ps1`.
- `.gitignore`, `.dockerignore`, `README.md`, `docs/phase1-validation.md`.
- Biên bản này, [chín ảnh trình duyệt thật và chú thích](evidence/README.md).

Đã đọc lại PHP, SQL, Dockerfile/Compose, PHP ini, CSS, README và script. Các file khung dành cho Nginx/monitoring/logging vẫn rỗng và không thuộc services của Compose.

## Tài nguyên và dữ liệu

Chỉ build/recreate các container `attendance-phase1-fresh-*`. Database tiếp tục dùng volume `attendance-phase1-fresh_phase1_data`. Không publish cổng MySQL. Tiến trình web có UID 33 (`www-data`), tài khoản ứng dụng không tạo được bảng.

Trước và sau nghiệm thu vẫn có các container cũ `attendance-web`, `attendance-phpmyadmin`, `attendance-db`, `attendance-system-mysql-1`, `attendance-system-nginx-1` và các container nhà hàng. Các cổng cũ 8085/8086, 5173, 8000, 5433 vẫn giữ nguyên. Nginx cũ đã exited và mailpit đã restart từ trước, không được can thiệp. Kiểm tra này xác nhận trạng thái/cấu hình quan sát được, không thay cho đối soát nội dung database của dự án khác.

Dữ liệu test có tiền tố ngẫu nhiên, chỉ dọn đúng ID hoặc lớp do lượt test tạo. Các ảnh mang nhãn kiểm thử; không giả làm dữ liệu thực. Profile Chrome tạm được ignore, không đưa vào Git. Không dọn tài nguyên cũ, không dùng truncate/prune/down -v.

## Rủi ro / phần chưa kiểm chứng

- Chưa có đăng nhập và phân quyền người dùng, HTTPS, audit ai đã sửa điểm danh. Phù hợp demo localhost; chưa kết luận sẵn sàng công khai Internet.
- Chưa kiểm tra Safari/Firefox, điện thoại thật, screen reader hoặc bàn phím toàn bộ luồng. Bảng dài cần cuộn ngang trên thiết bị nhỏ.
- Chưa kiểm thử tải, tranh chấp ghi đồng thời, phục hồi sau DB crash hoặc backup/restore. Hai người ghi cùng một buổi có thể ghi đè thay đổi của nhau; chưa có version check.
- Các trang danh sách lớp/sinh viên, chọn buổi và thống kê chưa phân trang; có thể chậm khi dữ liệu lớn. Lịch sử đã phân trang.
- Trigger bảo vệ các DML đã kiểm tra; tài khoản quản trị MySQL vẫn có quyền thay schema và vô hiệu hóa cơ chế bảo vệ. Migration mới đã áp dụng trên DB hiện có; chưa chạy lại toàn bộ init trên một volume trắng trong lần nghiệm thu này.
- Tên và mã sinh viên được đọc từ hồ sơ hiện tại, không lưu bản sao tên lịch sử. Không hỗ trợ chuyển lớp sau khi đã tạo roster.
- Image tag chưa khóa digest; hardening toàn stack và các thành phần triển khai sau chưa thực hiện.
- Chỉ kiểm tra lịch sử Git hiện truy cập được tại máy này; chưa kiểm tra remote hoặc dữ liệu đã từng bị xóa khỏi lịch sử. Hiện `git log --all -- .` tại workspace trả 0 commit.

**Điều kiện Commit 1:** phần mã nguồn Giai đoạn 1 và kiểm thử đạt. Trước thao tác commit thực tế cần repository riêng ở đúng workspace, kiểm tra staging chỉ chứa dự án và không có secrets; chờ người dùng yêu cầu commit. Không coi kết quả này là nghiệm thu toàn bộ đề thi hoặc hoàn thành yêu cầu ba commit/báo cáo 10 trang.
