#!/usr/bin/env python3
"""Convert the Marketplace documentation from Markdown to print-ready HTML.

Deliberately stdlib-only: this repo has no build dependencies (composer
`require` is empty, there is no node_modules), and the guides must stay
buildable on a clean checkout. It therefore supports only the Markdown
subset used by the documents in ../src — headings, fenced code,
ordered/unordered lists with one level of nesting, pipe tables, and inline
code/bold/italic/links. If you need something outside that subset, extend
this file rather than reaching for a dependency.

Usage: md2html.py <input.md> <output.html> <css-file> <version>
"""

import html
import re
import sys

LIST_RE = re.compile(r"^(\s*)(-|\d+\.)\s+(.*)$")
SEPARATOR_RE = re.compile(r"^[\s|:-]+$")


def render_inline(text):
    """Inline spans. Code is extracted first so its contents are never
    re-parsed as emphasis or links (e.g. `a_b_c` must stay literal)."""
    placeholders = []

    def stash_code(match):
        placeholders.append(
            "<code>%s</code>" % html.escape(match.group(1), quote=False)
        )
        return "\x00%d\x00" % (len(placeholders) - 1)

    text = re.sub(r"`([^`]+)`", stash_code, text)
    text = html.escape(text, quote=False)

    text = re.sub(
        r"\[([^\]]+)\]\(([^)]+)\)",
        lambda m: '<a href="%s">%s</a>' % (html.escape(m.group(2)), m.group(1)),
        text,
    )
    text = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"(?<!\*)\*([^*]+)\*(?!\*)", r"<em>\1</em>", text)

    return re.sub(r"\x00(\d+)\x00", lambda m: placeholders[int(m.group(1))], text)


def split_row(line):
    """Cells of a pipe-table row, without the leading/trailing pipes."""
    return [cell.strip() for cell in line.strip().strip("|").split("|")]


def is_separator(line):
    return bool(SEPARATOR_RE.match(line)) and "-" in line


def render_table(rows):
    """Rows are raw '|' lines. A separator in row 2 marks row 1 as a header."""
    if not rows:
        return ""

    has_header = len(rows) > 1 and is_separator(rows[1])
    body = rows[2:] if has_header else rows
    out = ["<table>"]

    if has_header:
        cells = "".join("<th>%s</th>" % render_inline(c) for c in split_row(rows[0]))
        out.append("<thead><tr>%s</tr></thead>" % cells)

    out.append("<tbody>")
    for row in body:
        if is_separator(row):
            continue
        cells = "".join("<td>%s</td>" % render_inline(c) for c in split_row(row))
        out.append("<tr>%s</tr>" % cells)
    out.append("</tbody></table>")

    return "\n".join(out)


def convert(markdown, version):
    # Strip HTML comments: they are build notes, not content.
    markdown = re.sub(r"<!--.*?-->", "", markdown, flags=re.DOTALL)

    out = []
    stack = []          # open list tags, outermost first
    para_buf = []       # consecutive lines of one paragraph
    table_buf = []
    code_buf = []
    in_code = False
    seen_title = False
    want_subtitle = False

    def close_lists(to_depth=0):
        while len(stack) > to_depth:
            out.append("</li></%s>" % stack.pop())

    def flush_table():
        if table_buf:
            out.append(render_table(list(table_buf)))
            del table_buf[:]

    def flush_para():
        # Source paragraphs may be hard-wrapped across several lines; they must
        # become one <p>, not one per line.
        nonlocal want_subtitle
        if not para_buf:
            return
        text = render_inline(" ".join(para_buf))
        del para_buf[:]
        if want_subtitle:
            out.append('<p class="subtitle">%s</p>' % text)
            out.append(
                '<div class="version"><strong>Version:</strong> %s</div>'
                % html.escape(version)
            )
            want_subtitle = False
        else:
            out.append("<p>%s</p>" % text)

    def flush_blocks():
        flush_para()
        flush_table()

    for raw in markdown.split("\n"):
        line = raw.rstrip()

        if line.strip().startswith("```"):
            if in_code:
                out.append(
                    "<pre><code>%s</code></pre>"
                    % html.escape("\n".join(code_buf), quote=False)
                )
                del code_buf[:]
            else:
                flush_blocks()
                close_lists()
            in_code = not in_code
            continue

        if in_code:
            code_buf.append(raw)
            continue

        if line.strip().startswith("|"):
            flush_para()
            close_lists()
            table_buf.append(line.strip())
            continue

        if not line.strip():
            # Ends a paragraph or table, but must not clear want_subtitle: the
            # title and its subtitle are separated by a blank line in source.
            flush_blocks()
            continue

        heading = re.match(r"^(#{1,3})\s+(.*)$", line)
        if heading:
            flush_blocks()
            close_lists()
            level = len(heading.group(1))
            body = render_inline(heading.group(2))
            if level == 1 and not seen_title:
                # Document title; the paragraph after it is the subtitle, and
                # the version badge follows, sourced from composer.json.
                out.append("<h1>%s</h1>" % body)
                seen_title = True
                want_subtitle = True
            else:
                out.append("<h%d>%s</h%d>" % (level, body, level))
                want_subtitle = False
            continue

        item = LIST_RE.match(line)
        if item:
            flush_blocks()
            indent, marker, body = item.groups()
            tag = "ul" if marker == "-" else "ol"
            depth = (len(indent) // 2) + 1  # 2+ spaces of indent nests one level

            if depth > len(stack):
                out.append("<%s>" % tag)
                stack.append(tag)
            else:
                close_lists(depth)
                out.append("</li>")
            out.append("<li>%s" % render_inline(body))
            continue

        # A plain line continuing a list item wraps into that item; otherwise
        # it accumulates into the current paragraph.
        if stack and not para_buf:
            out.append(" " + render_inline(line))
            continue

        flush_table()
        para_buf.append(line.strip())

    flush_blocks()
    close_lists()
    return "\n".join(out)


def main():
    if len(sys.argv) != 5:
        sys.exit("usage: md2html.py <input.md> <output.html> <css-file> <version>")

    src, dest, css_path, version = sys.argv[1:5]

    with open(src, encoding="utf-8") as fh:
        markdown = fh.read()
    with open(css_path, encoding="utf-8") as fh:
        css = fh.read()

    title = "Paystack Payments for Magento 2"
    match = re.search(r"^#\s+(.*)$", markdown, flags=re.MULTILINE)
    if match:
        title = match.group(1).strip()

    subtitle = ""
    sub_match = re.search(r"^#\s+.*\n\s*\n(.+)$", markdown, flags=re.MULTILINE)
    if sub_match:
        subtitle = " " + sub_match.group(1).strip()

    page = (
        '<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
        "<title>%s</title>\n<style>\n%s</style>\n</head>\n<body>\n%s\n</body>\n</html>\n"
        % (html.escape(title + subtitle), css, convert(markdown, version))
    )

    with open(dest, "w", encoding="utf-8") as fh:
        fh.write(page)


if __name__ == "__main__":
    main()
