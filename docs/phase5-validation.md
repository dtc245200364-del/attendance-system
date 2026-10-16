# Nghiệm thu Giai đoạn 5 — Hardening

Ngày 16/10/2026, UTC+7. Workspace `C:\Users\Admin\OneDrive\Desktop\attendance-system`, branch `main`, commit nền `76be11e`. Chưa stage, commit hoặc push. Không đổi nghiệp vụ/giao diện/cổng; không dùng down/prune/reset hoặc truy cập backup E.

## Kiểm kê và quyết định

Đã rà soát Dockerfile, Compose, cấu hình Nginx/PHP/MySQL/Prometheus/Grafana/Loki/Promtail, bootstrap/PDO và scripts kiểm thử. Biến môi trường được đọc trong bộ nhớ để kiểm tra độ dài/tính khác nhau, không in giá trị. Trước thay đổi đã lưu fingerprint MySQL; inventory container và hash file secrets được giữ cục bộ trong `secrets/` ignored, không đưa metadata dự án khác vào Git.

Đã có và giữ nguyên: PHP UID 33, Nginx UID 101, app DB DML-only, monitor USAGE-only, bốn mật khẩu ngẫu nhiên khác nhau, loopback-only ports, proxy/database/monitoring internal, security headers, CSRF/PDO/escaping, không publish DB/backend/exporter/Loki/Promtail. Không đổi tài khoản/quyền DB, `.env`, `.env.grafana`, source PHP nghiệp vụ, cấu hình header hoặc mạng đã an toàn.

Thiếu trước giai đoạn này: PHP và nhiều service còn writable root filesystem; nhiều service không giới hạn tài nguyên; cAdvisor mount toàn bộ VM root và Docker data; scripts dùng Docker cp không phù hợp với read-only container.

Đã thông báo trước về gián đoạn ngắn khi recreate riêng service ứng dụng/monitoring và khả năng mất session PHP. Áp dụng bằng `docker compose up -d --no-deps web nginx prometheus grafana cadvisor nginx-exporter mysql-exporter loki`, rồi `scripts/start-logging.ps1` cập nhật mount theo container ID mới và áp dụng Promtail. Không build mới, không restart/recreate DB hoặc phpMyAdmin, không thao tác lifecycle container dự án khác.

## Năm nhóm hardening và bằng chứng thực tế

| Nhóm | Cấu hình thực tế | Bằng chứng PASS |
|---|---|---|
| Non-root và ngăn tăng quyền | PHP 33:33, Nginx 101:101, Loki 10001:10001, exporters 1001 hoặc 65534, Prometheus 65534; Grafana UID 472/GID 0. MySQL daemon UID/GID 999 theo image, không ép user entrypoint. 9 service cap-drop ALL/no-new-privileges, không privileged | `docker top ... -eo uid,gid,pid,comm`; PHP `/proc/1/status`: CapEff toàn 0, NoNewPrivs=1 |
| Network isolation | Ba network internal; chỉ Nginx publish website, backend/MySQL không publish; 4 UI ports chỉ 127.0.0.1 | Inspect runtime/config và thử TCP thật từ Nginx tới IP MySQL:3306 bị chặn; PHP kết nối DB thành công |
| Secrets và DB least privilege | Bốn mật khẩu riêng 64 hex/256-bit, giữ nguyên file credentials; `.env*`, `secrets/`, runtime override/log/temp ignored. App chỉ SELECT/INSERT/UPDATE/DELETE đúng schema; monitor USAGE, tối đa 3 connections | SHOW GRANTS qua chính account, SELECT mysql.user/student data bị từ chối mã 1142; metadata max_user_connections=3 qua read-only root query; quét candidate Git files không lộ mật khẩu/token/key |
| Headers/session/version | Giữ CSP self/frame-ancestors none, X-Frame-Options DENY, nosniff, Referrer-Policy same-origin; expose_php/display_errors Off, Nginx server_tokens off, Apache ServerTokens Prod; cookie HttpOnly/SameSite Strict | HTTP thật trên HTML/CSS/404 đủ bốn headers, không X-Powered-By/phiên bản Server; cookie thật có hai thuộc tính; regression CSRF/XSS/SQLi pass |
| Filesystem/tài nguyên/mount | 9 service read-only, CPU/RAM/PID caps. PHP 3 tmpfs bounded noexec/nosuid UID 33. cAdvisor không còn full VM root/Docker-data mount; Promtail chỉ ba file log read-only | Mở source PHP và /etc/passwd bằng r+ thất bại, không sửa file; tmpfile ghi được. Inspect resource limits, không OOM. Kiểm tra đường dẫn host đầy đủ không tồn tại trong cAdvisor; metrics/logging vẫn có dữ liệu |

Promtail/cAdvisor vẫn UID 0 vì nhiệm vụ đọc log/cgroup/runtime; phpMyAdmin còn tiến trình Apache parent root theo image. Đây là ngoại lệ được ghi nhận, không tuyên bố mọi container đều non-root. Grafana GID 0 không phải UID 0; filesystem read-only và cap-drop vẫn áp dụng.

| Service | RAM limit | CPU limit | PID limit | Root filesystem |
|---|---:|---:|---:|---|
| web | 512 MiB | 1 | 256 | read-only |
| nginx | 128 MiB | 0.5 | 64 | read-only |
| prometheus | 512 MiB | 0.5 | 128 | read-only |
| grafana | 512 MiB | 0.5 | 128 | read-only |
| cadvisor | 256 MiB | 0.5 | 128 | read-only |
| nginx-exporter, mysql-exporter | 64 MiB mỗi service | 0.25 | 64 | read-only |
| loki | 512 MiB | 0.5 | 128 | read-only |
| promtail | 128 MiB | 0.25 | 64 | read-only |

RAM/CPU Loki/Promtail đã có từ Giai đoạn 4; giai đoạn này thêm PID caps. Nginx đã read-only từ Giai đoạn 2, chỉ bổ sung resource caps. Không áp dụng limit/user mới cho DB/phpMyAdmin để tránh recreate hoặc làm lỗi DB.

PHP tmpfs: `/tmp` 64 MiB, `/var/run/apache2` và `/var/lock/apache2` mỗi nơi 1 MiB, UID/GID 33. Mã nguồn nằm trong image read-only, không bind writable document root. Persistent data Prometheus/Grafana/Loki/positions vẫn dùng volume cũ; chỉ dữ liệu tạm/session PHP bị mất khi recreate.

### cAdvisor và Promtail

cAdvisor bỏ `/:/rootfs:ro`, bỏ `/var/lib/docker:/var/lib/docker:ro`. Giữ đúng bốn mount read-only:

```text
/proc -> /rootfs/proc
/sys -> /sys
/run/containerd/containerd.sock -> /run/containerd/containerd.sock
/var/run/docker.sock -> /var/run/docker.sock
```

Đổi `--containerd` sang đường dẫn socket trực tiếp. Docker factory đăng ký thành công, CPU/RAM/network có dữ liệu; 40 request thật làm web CPU/network counters tăng. Không cần hoàn nguyên mount. [Tài liệu cAdvisor](https://github.com/google/cadvisor/blob/master/docs/running.md) mô tả một số thống kê phụ thuộc host root có thể bị bỏ khi giảm mount; giữ phạm vi CPU/RAM/network của đề.

**Không coi socket :ro là Docker API read-only.** Docker/containerd sockets vẫn có quyền nhạy cảm, cAdvisor có thể đọc metadata ngoài dự án và cần trusted operator; `/proc` cũng là dữ liệu host nhạy cảm. Việc bỏ mount full filesystem giảm đường truy cập trực tiếp, không tạo tenant isolation hoặc sandbox host hoàn chỉnh. [Docker security](https://docs.docker.com/engine/security/) giải thích rủi ro quyền điều khiển daemon. Không thêm socket proxy/service mới trong lượt này; không mở privileged hoặc capability mới.

Promtail giữ chính xác ba file `*-json.log` của Nginx/web/db, không socket, không thư mục metadata container, không thu dự án khác. Vẫn dùng pipeline allowlist Giai đoạn 4. Sau recreate nguồn đã chạy start-logging để cập nhật mount. Retention/log rotation/EOL giữ các giới hạn đã công khai.

## Kiểm thử thực tế

| Lệnh/bộ kiểm tra ở lượt cuối | PASS | FAIL |
|---|---:|---:|
| `scripts/hardening-test.ps1` | 100 | 0 |
| `scripts/test.ps1` | 72 | 0 |
| `scripts/monitoring-test.ps1 -ResultPath docs/phase5-monitoring-results.json` | 54 | 0 |
| `scripts/logging-test.ps1 -ResultPath docs/phase5-logging-results.json` | 48 | 0 |
| `scripts/monitoring-access-test.ps1` | 4 | 0 |
| `scripts/acceptance-check.ps1` | 17 | 0 |
| **Tổng lượt cuối từng bộ** | **295** | **0** |

PHP lint riêng **9/9** file ứng dụng hợp lệ, không cộng lại vào 295 assertions. Compose config thành công. Các lệnh test phải chạy tuần tự để fixture không xung đột. Sau hoàn tất, `scripts/data-fingerprint.ps1 -OutputPath docs/phase5-data-after.json` đã lưu fingerprint và audit so sánh với before.

[Kết quả hardening](phase5-hardening-results.json), [monitoring](phase5-monitoring-results.json), [logging](phase5-logging-results.json), [fingerprint trước](phase5-data-before.json), [sau](phase5-data-after.json) lưu số liệu kiểm tra thực, không chứa passwords hoặc raw request log.

Ứng dụng regression kiểm đủ CRUD lớp/sinh viên/buổi điểm danh, trạng thái có mặt/vắng/đi muộn, lịch sử/thống kê, các ràng buộc và chống injection/XSS/CSRF. Fixture riêng được dọn bởi finally. Fingerprint bốn bảng đều **0 dòng trước và sau**, hash khớp; không tuyên bố bảo toàn các bản ghi không tồn tại. Metadata AUTO_INCREMENT có thể tăng do fixture, fingerprint ở đây chỉ so sánh dữ liệu hàng và count, không phải binary backup toàn database.

Prometheus **4 UP/0 DOWN**, nginx_up/mysql_up=1, 9 panel monitoring qua Grafana có dữ liệu. Loki `/ready` và datasource health thành công; Promtail healthy. 4 panel log trả dữ liệu thật qua Grafana API. Ba LogQL thành công:

```logql
{job="attendance",service="nginx"}
{job="attendance",service="nginx"} | json | status = 404
sum by (service) (count_over_time({job="attendance"}[5m]))
```

Request 404 mới được đối chiếu bằng request ID; PHP event thật và CSRF 403 được tìm trong Loki. 11 service chạy, mọi Docker healthcheck được cấu hình đều healthy, không OOM; Loki distroless kiểm readiness bằng API, không khai báo healthcheck giả. UI ports giữ 18087/18086/19090/13001 loopback; DB/PMA giữ nguyên ID/StartedAt.

### Baseline cho lần audit mới trên máy khác

Baseline phiên này ở `secrets/phase5-runtime-before.json`, `secrets/phase5-secret-hashes.json`, không vào Git vì chứa metadata local. Trước thay đổi ở một phiên audit mới, có thể tạo baseline bằng đoạn dưới tại project root. Không chạy đè baseline phiên đã có; không dùng baseline mới để che một thay đổi đã xảy ra. Trên máy hiện tại baseline đã có, bỏ qua bước này.

```powershell
$paths=@('secrets/phase5-runtime-before.json','secrets/phase5-secret-hashes.json')
if (@($paths | Where-Object { Test-Path $_ }).Count) { throw 'Baseline already exists; preserve it.' }
$inventory=@()
foreach ($id in @(docker ps -aq)) {
    $c=(docker inspect $id | ConvertFrom-Json)[0]
    $inventory+=@{id=$c.Id;project=$c.Config.Labels.'com.docker.compose.project';service=$c.Config.Labels.'com.docker.compose.service';startedAt=$c.State.StartedAt;running=$c.State.Running}
}
$inventory | ConvertTo-Json -Depth 5 | Set-Content -Encoding UTF8 $paths[0]
$hashes=@{}
foreach ($p in @('.env','.env.grafana','secrets/mysql-exporter.cnf')) { $hashes[$p]=(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash }
$hashes | ConvertTo-Json | Set-Content -Encoding UTF8 $paths[1]
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/data-fingerprint.ps1 -OutputPath docs/phase5-data-before.json
```

Docker phải hoạt động và credentials monitoring đã được tạo; không in `docker inspect`/Compose full ra màn hình vì có env secrets. Script hardening chạy từ project root, dùng credentials trong bộ nhớ/stdin; test PHP CLI chỉ nằm trong tmpfs ngoài document root. `copy-web-test.ps1` bảo toàn byte bằng Base64 và kiểm hash, không nới read_only để chạy tests.

## Lỗi và quan sát trong quá trình thực hiện

1. Docker cp bị từ chối sau bật read-only, dù `/tmp` là tmpfs. Đổi 5 scripts sang helper truyền stdin dưới UID 33; không thay cấu hình bảo vệ để né test.
2. Bản helper UTF-8 ban đầu bị Windows PowerShell chuyển tiếng Việt thành `?`, khiến `history filters` thất bại sau 54 assertion trước đó đã pass. Chuyển sang Base64 ASCII; decoder cần bỏ CRLF của transport, sau đó kiểm SHA256 nguyên byte. Lượt cuối đủ 72/72, không sửa ứng dụng/history hoặc giảm assertion.
3. SHOW GRANTS của MySQL đang dùng không hiển thị max-user-connections: assertion kiểm giới hạn qua chuỗi grants thất bại. Sửa phép đo bằng SELECT đúng metadata `mysql.user` qua root read-only; giá trị thực là 3. Account monitor vẫn bị cấm SELECT mysql.user.
4. Logging có một lượt **47 PASS/1 FAIL** vì Loki `/ready` trả 503 thoáng qua trong khi query/datasource hoạt động. Lần đọc tiếp trả ready. Bổ sung polling tối đa 12 lần, mỗi 2 giây; nếu không ready vẫn FAIL. Lượt cuối 48/48; chưa xác định nguyên nhân gốc của 503, không nới limits/quyền để che lỗi.
5. Lượt hardening đầu **97 PASS/1 FAIL** ở assertion đòi mọi container ngoài dự án giữ StartedAt. Container `mailpit` bên ngoài tự đổi StartedAt, quan sát RestartCount=90, exit code trước đó 1, không OOM; không có lifecycle command của lượt này nhắm tới nó, không sửa/dừng container đó. Tách kiểm tra thành DB/PMA ID/StartedAt/state giữ nguyên và mọi ID container khác còn tồn tại; lưu `externalStartTimeChanges=1` như quan sát, không tuyên bố mọi dịch vụ khác có uptime liên tục. Nguyên nhân restart ngoài phạm vi chưa điều tra.

295/0 là kết quả cuối theo phạm vi assertion đã nêu; các lỗi trước đó và biến động ngoài dự án được ghi rõ, không gộp các lượt chạy để làm tăng số PASS.

Rà soát cuối sau viết tài liệu: secret scan candidate Git files sạch, 7/7 script PowerShell tạo/sửa parse hợp lệ, `git diff --check` thành công. `nginx -t`, `promtool check config`, Compose config và readiness Loki/Promtail đều thành công; 4 targets vẫn UP. C còn 10,195,357,696 byte trống, D còn 107,768,975,360 byte. Có 17 file tạo/sửa trong workspace, index chưa stage, HEAD vẫn `76be11e`. Các kiểm tra rà soát bổ sung này không cộng trùng vào 295 assertions trong bảng.

## Rủi ro còn lại và đề xuất

- **Chưa đăng nhập/phân quyền:** GET trang quản lý không xác thực vẫn 200, ghi nhận là rủi ro chứ không coi là kiểm soát bảo mật đạt. CSRF không thay thế authorization. Chỉ chấp nhận demo localhost trên máy tin cậy; cần giai đoạn riêng thêm password_hash/password_verify, login/logout, session rotation và RBAC giảng viên/admin, kiểm tra quyền từng request. Không public hoặc dùng dữ liệu sinh viên thật trước đó. Không tự sửa toàn ứng dụng trong lượt này.
- Promtail EOL, UID root ngoại lệ; cAdvisor root/sockets/proc còn nhạy cảm. Socket proxy/daemon authorization là hướng củng cố riêng cần kiểm tương thích, không khẳng định bind :ro ngăn API mutation.
- Loopback HTTP, không TLS/HSTS/Secure cookie. Máy dùng chung hoặc phần mềm local vẫn có thể truy cập; không suy diễn cấu hình này an toàn cho Internet. Prometheus/Loki không auth riêng, chỉ internal/loopback; Grafana admin credential không công khai.
- Resource caps kiểm được hoạt động dưới regression 40 request, chưa load/soak test hoặc mô phỏng OOM/PID exhaustion. Không thử gây mất dịch vụ DB/host để minh chứng. 503 readiness Loki thoáng qua cần theo dõi thêm.
- Tmpfs session/temp không bền vững; noexec không ngăn interpreter đọc/chạy script trong tmpfs. Writable persistent volumes vẫn phải tin cậy tiến trình dịch vụ.
- Retention Loki 7 ngày chưa chờ kiểm xóa đủ 7 ngày; raw Docker log không thuộc retention Loki, có thể chứa query/IP, không đưa Git. Sau recreate/rotation nguồn cần cập nhật mount/restart collector và kiểm ingest.
- Inventory nhận thấy một container khác tự restart; không kết luận hoặc sửa nguyên nhân ngoài phạm vi. Cần chủ dự án đó kiểm tra riêng nếu yêu cầu uptime.
- Chưa chạy browser visual suite sau sửa helper; 9 panel metrics và 4 panel log kiểm dữ liệu bằng API, không tuyên bố đã tự kiểm mọi thao tác UI. Không có ảnh Giai đoạn 5 được tạo trong lượt này; [người dùng tự chụp](phase5-screenshots.md).
- Chưa chạy vulnerability image scanner/CVE audit, pentest hoặc audit ACL Windows; kiểm tra password shape phản ánh giá trị sinh 256-bit đã có, không chứng minh được entropy chỉ từ một mẫu.

## File thay đổi và kết luận

Sửa `docker-compose.yml`, `README.md`, `scripts/test.ps1`, `scripts/data-fingerprint.ps1`, `scripts/logging-test.ps1`, `scripts/monitoring-access-test.ps1`, `scripts/browser-test.ps1`. Tạo `scripts/copy-web-test.ps1`, `scripts/hardening-test.ps1`, `tests/hardening.php`; tài liệu/kết quả `docs/phase5-validation.md`, `docs/phase5-screenshots.md`, `docs/phase5-data-before.json`, `docs/phase5-data-after.json`, `docs/phase5-hardening-results.json`, `docs/phase5-monitoring-results.json`, `docs/phase5-logging-results.json`. Runtime override/baseline local ignored, không dự kiến commit. Không thay Dockerfile, SQL, code nghiệp vụ, ảnh, credentials hoặc backup E.

**Đủ điều kiện kỹ thuật chuẩn bị Commit 5 cho phạm vi bài thi cục bộ**, tối thiểu bốn nhóm được kiểm chứng thực tế. Không đủ để tuyên bố production-ready do các rủi ro đã nêu, nhất là thiếu authentication/authorization. Chưa stage, commit hoặc push; cần người dùng nghiệm thu và yêu cầu commit riêng.
