# Giai đoạn 6 — logic điểm danh, lịch sử và thống kê

Ngày kiểm tra: **17/10/2026, UTC+7**.

## Kết quả

Đã triển khai bản sửa vào `attendance-phase1-fresh-web-1` sau khi kiểm thử ghi trên stack riêng. **243 kiểm tra đạt, 0 thất bại** trong các lượt chạy cuối; ngoài ra 10 file PHP ứng dụng qua lint và cấu hình Nginx qua `nginx -t` trên cả stack kiểm thử và stack gốc.

| Bộ kiểm thử | Đạt | Bằng chứng |
|---|---:|---|
| HTTP/MySQL hồi quy | 72 | [phase6-integration-results.txt](phase6-integration-results.txt) |
| Logic Giai đoạn 6 và Nginx | 39 | [phase6-test-results.json](phase6-test-results.json) |
| Chrome thật, 390/768/1440px | 29 | Kết quả CLI của `browser-test.ps1`; [ảnh chụp thật](evidence/phase6/README.md) |
| Prometheus/Grafana/exporter/dashboard | 54 | [phase6-monitoring-results.json](phase6-monitoring-results.json) |
| Loki/Promtail/Grafana/quyền riêng tư log | 49 | [phase6-logging-results.json](phase6-logging-results.json) |

Số trên không cộng các lượt chạy lại trong quá trình phát triển. Trước lượt Chrome thành công, stack test thiếu network frontend nên host không truy cập được cổng test; đã thêm network riêng chỉ cho Nginx. Một lượt khởi động test ban đầu có xung đột IP cấp động; đã gán IP riêng cho ba service test. Không thay đổi network dự án gốc để xử lý hai vấn đề này. Loki có thời gian khởi động trả 503 rồi chuyển ready; kiểm thử cuối đạt.

## Phạm vi sửa

| File | Thay đổi |
|---|---|
| `app/attendance.php` | Kiểm tra phiên bản trong transaction; 409 khi form cũ; cảnh báo buổi tương tự có xác nhận; ID buổi; kiểm tra danh sách JSON và dấu kết thúc form. |
| `app/includes/attendance-version.php` (mới) | Khóa dùng chung giữa PHP worker, mã phiên bản ngẫu nhiên, HMAC ảnh chụp danh sách; so sánh tiêu đề. |
| `app/assets/attendance.js` (mới) | Gói toàn bộ trạng thái vào một trường JSON để tránh `max_input_vars`. |
| `app/history.php` | ID/link buổi; giải thích đây là trạng thái hiện tại theo buổi; nhãn thời gian bắt đầu buổi. |
| `app/statistics.php` | Làm rõ mẫu số, giữ nguyên công thức. |
| `app/includes/bootstrap.php` | Xử lý conflict 409; rollback lỗi chưa xử lý; log loại exception, basename file, dòng, SQLSTATE và mã driver được lọc. |
| `promtail/config.yml` | Cho phép các trường chẩn đoán mới và sự kiện `database_error`; tiếp tục loại bỏ dòng không đúng allowlist. |
| `scripts/logging-test.ps1` | Kiểm tra allowlist mới và chẩn đoán đi qua Promtail tới Loki thật. |
| `scripts/browser-test.ps1`, `scripts/copy-web-test.ps1` | Nhận Compose file để chạy browser/fixture trong stack riêng. |
| `tests/integration.php` | Lấy phiên bản mới và dấu hoàn tất khi gửi form điểm danh. |
| `docker-compose.phase6.yml`, `tests/phase6-proxy.conf` (mới) | Stack PHP/MySQL/Nginx riêng, mạng và volume riêng. |
| `tests/phase6.php`, `scripts/phase6-test.ps1` (mới) | Kiểm thử xung đột, cảnh báo, lớp đông, rollback và HTTP qua Nginx. |

Không sửa các file SQL, không migration, không thêm đăng nhập hoặc bảng nhật ký chỉnh sửa. Khi chuẩn bị Commit 6, các mật khẩu test literal đã được chuyển sang `.env.phase6` cục bộ bị ignore; chỉ kiểm tra cấu hình và cú pháp script cho thay đổi này, không khởi động lại stack test hoặc chạy lại 243 kiểm tra. `docker-compose.override.yml` cục bộ được script hiện có tạo lại để Promtail đọc đúng file log của container web mới; đây là file được Git ignore.

## Phiên bản và transaction

Mỗi buổi có một mã phiên bản ngẫu nhiên trong `/tmp/attendance-versions`, dùng chung giữa các worker của **một container web hiện tại**. Form mang HMAC của ID buổi, phiên bản và ảnh chụp `(student_id, status, updated_at)` đã sắp xếp. Không chỉ dựa vào timestamp độ chính xác giây.

Luồng lưu giữ `flock` của buổi, mở transaction, khóa dòng `sessions` và các dòng `attendance` bằng `FOR UPDATE`, tính lại phiên bản và so sánh. Không khớp thì rollback, trả HTTP 409 và hướng dẫn mở lại danh sách. Kiểm tra đủ ID và trạng thái trước khi commit; đổi phiên bản trước commit và giữ khóa tới khi transaction kết thúc. Nếu commit lỗi, form cũ bị vô hiệu hóa một cách bảo thủ; dữ liệu không bị chấp nhận bằng phiên bản cũ.

Đã kiểm thử:

- Hai tab cùng PHP session; hai người dùng khác session.
- Hai HTTP request gửi đồng thời: đúng một 302 và một 409; MySQL giữ kết quả người thắng.
- Đổi A → B → A vẫn từ chối form cũ; lưu không đổi trạng thái cũng vô hiệu form đã dùng.
- Token giả, mất file phiên bản, thiếu danh sách, JSON sai và trạng thái sai không làm mất kết quả trước.

**Giới hạn triển khai:** cơ chế phiên bản này dành cho một container web với nhiều PHP worker, đúng topology hiện tại. Không scale thêm replica web với `/tmp` riêng. Nếu cần nhiều replica hoặc cho phép công cụ khác ghi điểm danh trực tiếp, cần thiết kế version bền vững trong database và đánh giá migration riêng. Không xóa thủ công thư mục phiên bản khi ứng dụng đang phục vụ. Khi container được tạo lại, phiên PHP/file phiên bản tạm mất; người dùng cần tải lại trang. Không tuyên bố kiểm thử crash/power-loss hoặc topology nhiều replica.

## Buổi tương tự, lịch sử và thống kê

Khi tạo buổi, hệ thống khóa lớp, tìm các buổi cùng lớp trong khoảng đầu ngày đến đầu ngày kế tiếp. Tiêu đề bỏ dấu câu/khoảng trắng, so khớp không phân biệt hoa thường cho trường hợp bằng nhau, hoặc cảnh báo khi mức tương tự `similar_text` từ 80%. Đây là heuristic cảnh báo, không phải ràng buộc đồng nhất buổi học.

Cảnh báo trả 409, liệt kê ID/tiêu đề/giờ buổi hiện có. Người dùng có thể mở buổi cũ hoặc xác nhận tạo buổi riêng. Token xác nhận ràng buộc đầu vào và danh sách ứng viên; không tự xóa/gộp dữ liệu.

Lịch sử vẫn đọc trạng thái hiện tại trong `attendance`. Thời gian hiển thị/lọc là `sessions.starts_at`, không phải thời gian sửa. Không bổ sung hay dựng lại nhật ký quá khứ.

Công thức giữ nguyên: `(Có mặt + Đi muộn) / (Có mặt + Vắng mặt + Đi muộn) × 100%`. Chưa điểm danh không nằm trong mẫu số; mẫu số 0 hiển thị `—`.

## Danh sách đông

Runtime vẫn dùng `max_input_vars=1000`. Form có 5 trường ngoài danh sách ở chế độ thông thường. Nếu danh sách vượt giới hạn, nút lưu mặc định bị vô hiệu hóa và chỉ bật khi JavaScript hoạt động. JavaScript gửi một trường JSON thay cho hàng nghìn trường `status[...]`. Backend kiểm tra dấu hoàn tất, số lượng và từng ID/trạng thái; không lưu một phần. Giới hạn body Nginx vẫn là 1 MiB, vượt giới hạn trả 413 trước khi xử lý PHP.

Đã kiểm thử 1.101 sinh viên: POST thông thường bị PHP cắt trả 422 và không đổi dòng nào; JSON lưu đủ 1.101 dòng; payload có trạng thái sai ở cuối rollback cả những dòng đã cập nhật trước đó. Chrome thật đã gửi form qua JavaScript và xác nhận lưu Có mặt/Vắng/Đi muộn. Chưa kiểm thử browser render 1.101 dòng trên thiết bị vật lý.

## Log và giám sát

Log mới giữ request ID, loại exception được giới hạn, tên file không chứa đường dẫn đầy đủ, số dòng và mã lỗi PDO có định dạng giới hạn. Không ghi message exception, stack trace, SQL, POST, mật khẩu, token, cookie hoặc dữ liệu sinh viên. Handler lỗi kết nối không tiếp tục ném lỗi khi thử rollback.

Đã kiểm tra chẩn đoán exception an toàn được gửi vào stderr container rồi đi qua Promtail tới Loki; trường `exception_type`/`error_file` còn nguyên. Canary trong message exception không xuất ra log. Các truy vấn dashboard Prometheus/Grafana và Loki/Grafana có dữ liệu thật, bốn target Prometheus UP, Promtail healthy.

## Bảo toàn dữ liệu gốc

So sánh [trước](phase6-data-before.json) và [sau](phase6-data-after.json): **số dòng và SHA-256 cả bốn bảng giống hệt nhau**. Hash bao gồm toàn bộ nội dung dòng theo thứ tự khóa, kể cả timestamp.

| Bảng | Số dòng trước/sau | Kết quả |
|---|---:|---|
| classes | 2 / 2 | Không đổi |
| students | 4 / 4 | Không đổi |
| sessions | 3 / 3 | Không đổi |
| attendance | 6 / 6 | Không đổi |

Giữ nguyên buổi 182/183/184, mã sinh viên `CNTT01` và `SV00`, cùng 1 Có mặt, 1 Vắng, 1 Muộn, 3 Chưa điểm danh. Mọi kiểm thử CRUD/điểm danh ghi dữ liệu chỉ chạy trên `attendance-phase6-test`, volume `attendance-phase6-test_phase6_test_data`; fixture được dọn theo ID của chính lượt chạy. Không xóa database/volume nào. Sau kiểm thử, stack test được dừng và volume được giữ lại.

Stack gốc chỉ nhận GET, POST CSRF sai bị chặn và log chẩn đoán thử; các log/metric/session tạm phát sinh là hoạt động kiểm thử được phép. Không thay đổi container của dự án khác. Ở lượt kiểm thử chưa commit/push. Commit 6 được tạo/push ở bước quản lý Git tiếp theo theo phê duyệt riêng của người dùng. Thư mục `docs/screenshots/` đã có từ trước và không bị sửa trong lần này.

## Chạy lại

```powershell
# Kiểm thử ghi: luôn chỉ định Compose file riêng.
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml up -d --build
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/phase6-test.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/browser-test.ps1 -Port 18097 -DebugPort 19237 -ComposeFile docker-compose.phase6.yml -ComposeEnvFile .env.phase6 -EvidenceDirectory docs/evidence/phase6

# Stack gốc: kiểm thử telemetry, không ghi dữ liệu điểm danh.
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/monitoring-test.ps1 -ResultPath docs/phase6-monitoring-results.json
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/logging-test.ps1 -ResultPath docs/phase6-logging-results.json
docker compose --env-file .env.phase6 -f docker-compose.phase6.yml stop
```

Compose test yêu cầu PHASE6_DB_PASSWORD và PHASE6_ROOT_PASSWORD từ file .env.phase6 bị Git ignore; không có mật khẩu literal trong repository. Giữ nguyên file này khi dùng lại volume test đã khởi tạo; không đổi mật khẩu chỉ trong env khi database đã tồn tại. Xem README để tạo file cho stack test mới. Không dùng credentials hay volume gốc. Không chạy `scripts/test.ps1` hoặc `browser-test.ps1` mặc định trên stack gốc trong quy trình chỉ cho phép thử ghi ở môi trường riêng.

## Kết luận và tồn tại

Đủ điều kiện nghiệm thu **phạm vi sửa Giai đoạn 6 đã được phê duyệt trên môi trường cục bộ một container web**. Không còn kiểm thử thất bại trong các lượt cuối.

Các điểm giữ nguyên: chưa có xác thực/phân quyền; không có audit trail; hai buổi 183/184 vẫn cần người dùng đối soát nghiệp vụ; chưa có quy định mã sinh viên của trường để kết luận `CNTT01`/`SV00` sai. Promtail EOL và các giới hạn hạ tầng đã công bố ở giai đoạn trước không được giải quyết trong lần sửa này. Không coi kết quả này là nghiệm thu môi trường public hoặc toàn bộ yêu cầu đề tài chưa được cung cấp.
