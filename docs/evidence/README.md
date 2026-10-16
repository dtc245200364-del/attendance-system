# Ảnh minh chứng nghiệm thu Giai đoạn 1

Chụp trực tiếp bằng Chrome headless ngày **16/10/2026** tại website đang chạy trên máy host, sau khi build bản sửa cuối của Giai đoạn 1. Không dùng AI hoặc chỉnh sửa nội dung ảnh.

Ảnh dùng lớp/sinh viên/buổi có nhãn **kiểm thử**, mã ngẫu nhiên bắt đầu bằng `B`. Dữ liệu này được tạo riêng cho lượt test và đã dọn sau khi chụp; không phải danh sách sinh viên hay điểm danh thực tế. Các ảnh bảng thống kê được chụp trước khi submit điểm danh, nên thể hiện trạng thái chưa ghi và tỷ lệ `—`.

| File | Nội dung |
|---|---|
| [attendance-390.png](attendance-390.png) | Điểm danh trên viewport 390px |
| [attendance-390-scrolled.png](attendance-390-scrolled.png) | Cuộn ngang bảng để thao tác trạng thái ở 390px |
| [attendance-768.png](attendance-768.png) | Điểm danh trên viewport 768px |
| [attendance-1440.png](attendance-1440.png) | Điểm danh trên viewport 1440px |
| [statistics-390.png](statistics-390.png) | Thống kê trên viewport 390px |
| [statistics-768.png](statistics-768.png) | Thống kê trên viewport 768px |
| [statistics-1440.png](statistics-1440.png) | Thống kê trên viewport 1440px |
| [validation-error.png](validation-error.png) | Lỗi ngày lọc không hợp lệ |
| [empty-history.png](empty-history.png) | Khoảng ngày không có dữ liệu |

Ảnh toàn trang được tạo bằng `Page.captureScreenshot`; viewport kiểm tra có chiều cao 900px. File ảnh dài hơn 900px là ảnh toàn trang, không phải thay đổi viewport hoặc ghép ảnh. Đã mở xem ảnh điểm danh ở 390/768px, ảnh cuộn ngang, thống kê desktop và lỗi validation để đối chiếu trực quan. Bảng cuộn trong khung trên thiết bị nhỏ, không kéo tràn toàn trang.

Tái tạo bằng `scripts/browser-test.ps1`. Chrome giữ profile kiểm thử trong thư mục đã được Git ignore; script đóng phiên Chrome riêng khi kết thúc.
