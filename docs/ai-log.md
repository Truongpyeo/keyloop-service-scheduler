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