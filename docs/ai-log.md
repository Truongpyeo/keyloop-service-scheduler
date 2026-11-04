# AI collaboration log
Tool: Claude Code + Laravel Boost (MCP). For each step: what I asked, what the AI produced, what I changed and why.
## Setup
- Cài Laravel Boost: tự thêm CLAUDE.md, AGENTS.md, .mcp.json và các skill (laravel-best-practices, testing-best-practices) để Claude Code có tài liệu Laravel 13 chuẩn trước khi viết code.

## Step 1 – System design
- Prompt: đưa toàn bộ quyết định kiến trúc (bảng, API, điều kiện trùng giờ, khóa FOR UPDATE, idempotency) và yêu cầu AI viết DESIGN.md, chưa code.
- AI tự điền 6 chi tiết tôi chưa quyết. Tôi giữ 4 (thứ tự khóa theo id, hủy idempotent, health 503, out of scope).
- Tôi sửa 3 điểm:
  - AI ghi PHPUnit trong khi project dùng Pest → thống nhất Pest.
  - Idempotency thiếu trường hợp cùng key nhưng khác nội dung → thêm hash body, trả 422.
  - Kiểm tra giờ mở cửa chỉ xét giờ bắt đầu và không nói timezone → kiểm tra cả ends_at, quy đổi theo timezone đại lý.
- Cách kiểm tra: tự đọc từng mục, dùng thêm một AI assistant khác (Claude chat) để review chéo thiết kế, xem sơ đồ Mermaid hiển thị trên GitHub.
## Step 2 – Phản biện thiết kế
- Prompt: yêu cầu AI đóng vai senior engineer, liệt kê 8 điểm yếu của DESIGN.md, chưa sửa file.
- AI đưa ra 8 điểm. Điểm quan trọng nhất: MySQL REPEATABLE READ khiến câu SELECT kiểm tra lại có thể đọc ảnh chụp cũ → vẫn đặt trùng. Tôi đối chiếu với tài liệu MySQL, xác nhận đúng → đổi sang locking read.
- Nhận: snapshot read (1), trùng lịch theo xe (3), chuẩn hóa giờ trước khi băm idempotency, thử lại khi deadlock + trả 503 (2), bắt buộc giờ có múi giờ (4), availability tính trong bộ nhớ (6), test biên giờ đóng cửa (7).
- Bỏ (ghi vào Out of scope): is_active, lịch nghỉ/bảo trì, validate seed, test ngày đổi giờ, chỉnh innodb_lock_wait_timeout. Lý do: hạn nộp ngắn, ưu tiên phần đảm bảo không đặt trùng.
- Lưu ý: AI đề xuất "test hai transaction đan xen", nhưng test Pest dùng SQLite không mô phỏng được khóa của MySQL → tôi chọn kiểm tra bằng script gửi request song song vào MySQL thật.
- Quyết định thêm: Docker tự viết (không dùng Sail) để người chấm chỉ cần Docker; frontend Blade làm sau cùng nếu còn thời gian.

- **Lỗi AI bỏ sót:** AI báo 6/6 test pass, nhưng chỉ chạy trên Herd. Khi tôi chạy trong Docker, test health fail (200 thay vì 503). Tôi tìm ra nguyên nhân: biến môi trường DB_CONNECTION=mysql của docker-compose thắng <env> trong phpunit.xml (thiếu force="true"), nên test trong container chạy trên MySQL thật và RefreshDatabase có thể xóa dữ liệu app. Đã sửa bằng force="true" và thêm test bảo vệ khẳng định test luôn chạy SQLite in-memory.
- Bài học: luôn chạy test trong đúng môi trường người chấm sẽ dùng, không tin kết quả "pass" từ môi trường khác.

- Kết quả trong Docker: health 200 khi DB chạy, 503 khi tắt MySQL; 7/7 test pass trong container (sau khi sửa force="true").
- Ghi chú cho README: người chấm dùng Windows PowerShell 5.1 phải gõ curl.exe (curl là alias của Invoke-WebRequest).

## Step 4 – Data model
- Prompt: 8 bảng theo DESIGN.md, FK restrict, 4 index, seed dữ liệu demo cố định.
- AI tự phát hiện: cast immutable_datetime không tự đổi sang UTC → ghi chú cho bước 5 phải gọi ->utc() trước khi lưu.
- Tôi kiểm tra trên MySQL thật (SHOW CREATE TABLE): [kiểu cột starts_at, số index, FK restrict].
- Test trong Docker: [số test pass]. Seed không chạy lại khi restart.