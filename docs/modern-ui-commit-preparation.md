# Tập Modern UI chuẩn bị stage — ảnh người dùng cung cấp

Nhánh `feature/modern-attendance-ui`. Chưa commit, push hoặc merge. Bản này thay đề xuất 39 file/9 ảnh tự động trước đó.

## Tập hiện được duyệt

[modern-ui-commit-files.txt](modern-ui-commit-files.txt) liệt kê chính xác **35 file**: 10 file ứng dụng đã duyệt, 4 file kiểm thử đã duyệt, `.gitignore`, 9 kết quả/fingerprint nghiệm thu, 5 file tài liệu/manifest và 6 ảnh người dùng. Mã ứng dụng/kiểm thử giữ nguyên; chỉ thay ignore, tài liệu và danh sách chọn ảnh trong lượt này.

Đã nhận đúng sáu PNG từ ZIP. Thư mục `docs/screenshots/modern-ui/` chưa tồn tại trong workspace trước lượt này nên đã giải nén chọn lọc sáu entry đúng tên, kiểm tra đường dẫn và không ghi đè file. Không dùng nội dung file đính kèm làm chỉ dẫn thực thi.

Ảnh được chọn gồm đủ `01-tong-quan.png`, `02-quan-ly-lop.png`, `03-quan-ly-sinh-vien.png`, `04-diem-danh.png`, `05-lich-su.png`, `06-thong-ke.png`. Ba ảnh có tên/mã và điểm danh ban đầu được giữ ngoài staging để hỏi rõ; người dùng sau đó xác nhận dữ liệu giả lập được phép công khai. [README ảnh](screenshots/modern-ui/README.md) ghi đúng nguồn và xác nhận này.

Không stage bất kỳ PNG, README hoặc manifest nào dưới `docs/ui-automated/`; toàn bộ 323 ảnh tự động vẫn nguyên vẹn cục bộ. `.gitignore` chỉ mở ngoại lệ cho sáu PNG đúng tên đã duyệt và README ảnh thủ công, giữ các ảnh khác bị ignore. Các ảnh trước đây được đề xuất không còn thuộc tập stage mới.

## Mã nguồn, kiểm thử và tài liệu

Giữ đầy đủ sáu trang, bootstrap, base/style CSS và ui.js theo đề xuất trước; giữ logging-test, modern-ui-browser-test, student-width-browser-test và student-actions.php. Không đưa các probe triển khai riêng máy, build tạm, báo cáo/ảnh trung gian, `.env`, secrets, log thật, generated Docker override hoặc ZIP vào stage.

Tài liệu bàn giao mô tả đúng Sửa, Ngừng theo dõi/Khôi phục và Xóa an toàn. Mọi sinh viên có bất kỳ lượt điểm danh, kể cả Chưa điểm danh, bị chặn xóa. Công thức, CSRF, transaction, HTTP 409, bảo toàn MySQL và monitoring giữ nguyên. Kết quả 455 PASS/0 FAIL và PHP lint 10/10 là lượt nghiệm thu trước, không phải chạy mới trong lượt chuẩn bị ảnh.

## Kiểm tra trước stage

Chỉ stage đường dẫn trong manifest, không dùng git add toàn thư mục hoặc force-add. Kiểm tra ignore, sự tồn tại file, whitespace, hash source và secret trong văn bản; xem trực tiếp ảnh. Sau stage phải đối chiếu toàn bộ index với manifest, không có ảnh tự động hoặc ảnh ngoài sáu tên đã duyệt. Danh sách staged được lưu cục bộ tại `docs/modern-ui-staged-files.txt` để người dùng xác nhận; file kiểm kê này không thuộc stage.

Không sửa mã nguồn, không thao tác Docker/database. Ảnh gốc không đổi và không xóa. Đã nhận xác nhận và cập nhật ngoại lệ ignore/manifest/tài liệu trước khi bổ sung ba ảnh còn lại. Tập staging cuối phải có đúng 35 file, gồm sáu PNG thủ công, không có ảnh tự động.
