# Modern UI — bàn giao trước commit

Cập nhật ngày 17/10/2026, nhánh `feature/modern-attendance-ui`, nền Commit 6 `11d81ab27cb82e77a1d08f6fb9f4804c6d5a89da`. Tài liệu này mô tả phạm vi cuối cùng và thay thế hướng dẫn bàn giao UI ban đầu. Đã stage tập file được duyệt để người dùng xác nhận; chưa commit, push hoặc merge.

## Phạm vi sáu trang

| Trang | Nội dung |
| --- | --- |
| Tổng quan | Sidebar xanh đậm, breadcrumb, KPI, buổi gần đây có ID, biểu đồ trạng thái từ truy vấn tổng hợp dữ liệu thật. |
| Lớp học | Thêm/sửa lớp, sĩ số đang học; chỉ xóa lớp trống không có sinh viên hoặc buổi học. |
| Sinh viên | Hai khung trắng rộng bằng vùng nội dung Tổng quan; năm cột, avatar, trạng thái, tìm tên/mã/lớp; ba nút Sửa, Ngừng theo dõi/Khôi phục, Xóa. |
| Điểm danh | Tạo/chọn buổi, ID/lớp/tiêu đề/thời gian; bốn trạng thái và chỉ lưu khi người dùng xác nhận. |
| Lịch sử | Lọc lớp/sinh viên/khoảng ngày, phân trang, ID buổi; hiển thị trạng thái hiện tại theo buổi và thời gian bắt đầu, không phải nhật ký chỉnh sửa. |
| Thống kê | Bốn KPI trạng thái, số lượt từng sinh viên và tỷ lệ chuyên cần theo công thức đã có. |

UI dùng HTML/CSS và SVG nội bộ, không framework/CDN/font mạng hay ảnh AI. Responsive dùng thẻ sinh viên trên màn hình nhỏ và vùng cuộn nội bộ cho bảng rộng. Không giấu thao tác quan trọng.

## Sửa, ngừng theo dõi và xóa an toàn

Sửa mở form bên dưới hồ sơ với thông tin hiện tại. Tìm kiếm là thao tác phía trình duyệt; không tự lưu form. Ngừng theo dõi/Khôi phục cập nhật trạng thái theo cơ chế hiện có, giữ nguyên lịch sử và số liệu.

Phạm vi cuối cùng **có bổ sung xử lý nghiệp vụ xóa an toàn** trong `app/students.php`, không chỉ thay giao diện. Chỉ xóa sinh viên không có bất kỳ bản ghi điểm danh hoặc quan hệ nghiệp vụ cần bảo toàn. Mọi lượt điểm danh, **kể cả Chưa điểm danh**, đều chặn xóa; hướng dẫn dùng Ngừng theo dõi. Hộp thoại hiển thị tên/mã, xác nhận được bảo vệ bằng CSRF và HMAC; server kiểm tra lại trong transaction với khóa hồ sơ, kiểm tra FK RESTRICT/NO ACTION và từ chối khi cấu trúc quan hệ chưa xác minh được. Không bổ sung ON DELETE CASCADE hoặc xóa lịch sử.

`post_action()` trong bootstrap được bổ sung nhận thông báo thành công từ callback. JavaScript hỗ trợ mở form, hộp thoại/hủy, tìm kiếm và màu trạng thái; không tự động POST hoặc lưu điểm danh. Đây là các thay đổi đã được phê duyệt trong quá trình hoàn thiện Modern UI.

## Bảo toàn nghiệp vụ và dữ liệu

Giữ CSRF, prepared statements, escaping, validation, transaction/rollback, cơ chế phiên bản và HTTP 409 chống form cũ; danh sách vượt max_input_vars được gửi đầy đủ hoặc từ chối toàn bộ. Không thay `attendance.js`, `attendance-version.php`, `db.php`, schema, migrations hoặc công thức chuyên cần.

Công thức: `(Có mặt + Đi muộn) / (Có mặt + Vắng mặt + Đi muộn) × 100%`. Chưa điểm danh không nằm trong mẫu số; mẫu số 0 hiển thị `—`. Sinh viên ngừng theo dõi vẫn được tính theo lịch sử đã có.

Lượt nghiệm thu gần nhất đối chiếu [trước](modern-ui-acceptance-data-before.json) / [sau](modern-ui-acceptance-data-after.json): số dòng và SHA256 cả bốn bảng giữ nguyên, gồm timestamp: **3 lớp, 3 sinh viên, 3 buổi, 6 lượt điểm danh**. Đây là số liệu tại thời điểm kiểm thử, không phải dữ liệu mẫu phải tái tạo. Không chuẩn hóa mã hoặc thay dữ liệu gốc.

## Phiên bản đang chạy

- Chính: http://127.0.0.1:18087 — có dữ liệu gốc.
- Kiểm thử: http://127.0.0.1:18097 — database độc lập, có thể trống sau khi dọn fixture.

Sáu đường dẫn: `/index.php`, `/classes.php`, `/students.php`, `/attendance.php`, `/history.php`, `/statistics.php`. Chọn buổi có sẵn từ giao diện; không hard-code ID chỉ tồn tại trên một máy.

CSS đã duyệt được chuyển từ preview sang image web chính, chỉ thay `assets/style.css`; không sao chép database. Phiên bản triển khai đã được đối chiếu hash toàn bộ file ứng dụng. Hai khung Sinh viên khớp Tổng quan với sai lệch 0px ở năm viewport; tại 1366/1440/1920px rộng tương ứng 1044/1118/1598px, giữ padding chung và sidebar.

Web dùng image COPY, restart không nạp file mới. Lần triển khai trước chỉ thay web, làm mới liên kết log cục bộ theo script hiện có và tái tạo Promtail để tiếp tục thu thập; không restart MySQL, Nginx, Prometheus, Grafana hoặc Loki. Cấu hình pipeline và named volumes giữ nguyên. Không chạy lệnh triển khai trong công việc chuẩn bị commit này.

## Kết quả nghiệm thu đã thực hiện

| Bộ kiểm thử | PASS | FAIL | Kết quả |
| --- | ---: | ---: | --- |
| HTTP/MySQL | 72 | 0 | [Integration](modern-ui-acceptance-integration.txt) |
| Logic Giai đoạn 6 | 39 | 0 | [Phase 6](modern-ui-acceptance-phase6.json) |
| Sinh viên/xóa an toàn | 39 | 0 | [Student actions](modern-ui-acceptance-student-actions.json) |
| Chrome sáu trang | 175 | 0 | [Browser](modern-ui-acceptance-browser.json) |
| Hình học khung/cột/nút | 27 | 0 | [Layout](modern-ui-acceptance-student-layout.json) |
| Prometheus/Grafana | 54 | 0 | [Monitoring](modern-ui-acceptance-monitoring.json) |
| Loki/Promtail | 49 | 0 | [Logging](modern-ui-acceptance-logging.json) |
| **Tổng số assertion, có kiểm tra giao nhau** | **455** | **0** | PHP lint riêng **10/10** |

Chrome kiểm tra 390, 768, 1366, 1440, 1920px; bộ hình học thêm 1024px. Bốn target Prometheus UP, dashboard có dữ liệu, log Nginx/PHP mới đến Loki, thông tin nhạy cảm không lọt qua các canary đã thử. MySQL log dùng khoảng nhìn lại 24h để đọc sự kiện có thật, không restart DB để tạo log mới. Đây là kết quả lượt nghiệm thu đã chạy, không phải một lượt chạy mới trong bước dọn file.

Người dùng cung cấp sáu ảnh Chrome thật từ cổng 18087 trong ZIP; đã lấy đúng sáu file vào `docs/screenshots/modern-ui/`, không sửa byte ảnh. [Danh sách sáu ảnh](screenshots/modern-ui/README.md) gồm đủ sáu trang. Người dùng đã xác nhận dữ liệu hiển thị, kể cả tên/mã và điểm danh, là giả lập và được phép công khai trên GitHub; do đó cả sáu ảnh thuộc tập staging được duyệt. Toàn bộ 323 ảnh tự động, README/manifest ảnh tự động và ảnh nhạy cảm cũ giữ cục bộ, không thuộc tập commit hiện tại.

## Tái hiện kiểm thử

Chỉ dùng project `attendance-phase6-test`, Compose test, `.env.phase6` và volume test độc lập theo README Giai đoạn 6. Không dùng database gốc cho bất kỳ bộ kiểm thử có ghi nào. Chạy các bộ tuần tự, không song song vì dùng chung fixture.

```powershell
# Nếu stack test đã tồn tại, dùng lại credentials/volume; không reset.
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml up -d --build
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/lint.php
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/integration.php
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/phase6.php
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/student-actions.php
powershell -NoProfile -ExecutionPolicy Bypass -Command "& './scripts/modern-ui-browser-test.ps1' -StudentActions -ViewportWidths @(390,768,1366,1440,1920) -ResultPath docs/modern-ui-acceptance-browser.json -EvidenceDirectory docs/ui-automated/modern-ui-acceptance"
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/student-width-browser-test.ps1 -CompactStudents -ResultPath docs/modern-ui-acceptance-student-layout.json -EvidenceDirectory docs/ui-automated/modern-ui-acceptance-layout
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/monitoring-test.ps1 -ResultPath docs/modern-ui-acceptance-monitoring.json
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/logging-test.ps1 -ResultPath docs/modern-ui-acceptance-logging.json -MySqlLogLookback 24h
```

Hai script monitoring/logging kiểm tra hệ thống chính bằng truy vấn/traffic đọc, request CSRF sai bị chặn và log chẩn đoán an toàn; không ghi dữ liệu nghiệp vụ. Không đổi cấu hình để làm kiểm thử đạt. Đổi tham số lookback có validation trong `scripts/logging-test.ps1` là hỗ trợ kiểm thử, không đổi pipeline.

Ảnh khi chạy lại sẽ bị ghi đè bởi Chrome, fixture ID/mã có thể khác; chỉ chụp trên stack độc lập. Manifest hash mô tả bộ ảnh hiện được đề xuất commit, không yêu cầu lần chạy sau sinh PNG byte-identical.

## Giới hạn và tập commit

Chưa chứng nhận Safari/Firefox, thiết bị vật lý, accessibility toàn diện hoặc tải lớn ngoài các tình huống đã thử. Hệ thống local chưa có đăng nhập/phân quyền/HTTPS; không tự bổ sung trong Modern UI. Giới hạn một web của cơ chế phiên bản Giai đoạn 6 giữ nguyên. Không mô phỏng chờ đủ bảy ngày để kiểm tra xóa log theo retention.

Danh sách đề xuất chính xác: [modern-ui-commit-files.txt](modern-ui-commit-files.txt). Phân loại và các file giữ ngoài commit được giải thích trong [modern-ui-commit-preparation.md](modern-ui-commit-preparation.md). Các báo cáo trung gian cục bộ phản ánh trạng thái ở từng thời điểm; không dùng chúng thay tài liệu bàn giao này.
