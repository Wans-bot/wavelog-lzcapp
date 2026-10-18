# Wavelog 第三方组件许可说明（随 LPK 分发）

本应用打包分发的是 Wavelog 3.2.3 官方镜像（`ghcr.io/wavelog/wavelog:3.2.3`）。
Wavelog 项目本体为 MIT 许可（见同目录 `WAVELOG-MIT-LICENSE.txt`）。

镜像与源码仓库内还包含第三方组件，上游仓库中保留了各自的许可证原文（本 LPK 不再重复复制全文）：

| 组件 | 许可 | 位置（镜像内 / 上游仓库） |
| --- | --- | --- |
| firebase/php-jwt（JWT 解析） | BSD-3-Clause | `src/jwt/LICENSE` |
| php-gettext（gettext 兼容库） | GPL-2.0-or-later | `application/hooks/gettext/vendor/php-gettext/COPYING` |
| Font Awesome Free | 代码 MIT / 字体 SIL OFL 1.1 / 图标 CC BY 4.0 | `assets/fontawesome/LICENSE.txt` |
| Twemoji Country Flags | CC-BY 4.0 | `assets/fonts/TwemojiCountryFlags/LICENCE.md` |
| DejaVu 字体（标签打印） | Bitstream Vera 字体许可 | `src/Label/font/unifont/DejaVu_LICENSE.txt` |
| Bootstrap / select2 / EasyMDE 等前端库 | MIT | 各目录内 LICENSE 文件 |

对应源码获取方式：https://github.com/wavelog/wavelog （tag `3.2.3`）。
