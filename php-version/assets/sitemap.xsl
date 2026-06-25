<?xml version="1.0" encoding="UTF-8"?>
<!--
   Human-readable sitemap stylesheet — kicks in when a person (admin,
   curious SEO auditor, anyone clicking the "View Sitemap" button in
   /admin.php?tab=ai-blogger) opens /sitemap.xml in a browser.
   Search engines ignore the <?xml-stylesheet?> processing instruction,
   so this is purely a presentation layer.
-->
<xsl:stylesheet version="1.0"
                xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
                xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
                exclude-result-prefixes="sm image">
  <xsl:output method="html" encoding="UTF-8" indent="yes"/>
  <xsl:template match="/">
    <html lang="en">
    <head>
      <meta charset="UTF-8"/>
      <title>XML Sitemap — Maventech Software</title>
      <meta name="robots" content="noindex"/>
      <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background: #F5F7FA; color: #0F172A; margin: 0; padding: 32px; }
        .wrap { max-width: 1200px; margin: 0 auto; background: #fff;
                border: 1px solid #E2E8F0; border-radius: 12px;
                box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
        header { background: linear-gradient(180deg, #0B5CFF 0%, #0540B7 100%);
                 color: #fff; padding: 24px 28px; }
        header h1 { margin: 0 0 4px; font-size: 22px; font-weight: 650; }
        header p  { margin: 0; opacity: .85; font-size: 13px; }
        header .count-chip { display: inline-block; padding: 3px 10px;
                 border-radius: 999px; background: rgba(255,255,255,.18);
                 font-size: 12px; font-weight: 600; margin-left: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 14px; text-align: left;
                 border-bottom: 1px solid #EEF1F6; font-size: 13px;
                 vertical-align: top; }
        th { background: #F8FAFC; font-weight: 600; color: #475569;
             font-size: 12px; text-transform: uppercase; letter-spacing: .02em; }
        tr:hover td { background: #FAFBFD; }
        td.url a { color: #0B5CFF; text-decoration: none; word-break: break-all; }
        td.url a:hover { text-decoration: underline; }
        td.priority, td.freq, td.date { white-space: nowrap; color: #64748B;
                 font-variant-numeric: tabular-nums; font-size: 12px; }
        .prio-pill { display: inline-block; padding: 2px 8px; border-radius: 999px;
                 font-size: 11px; font-weight: 600; }
        .prio-high { background: #DCFCE7; color: #166534; }
        .prio-med  { background: #DBEAFE; color: #1E3A8A; }
        .prio-low  { background: #F1F5F9; color: #475569; }
        @media (prefers-color-scheme: dark) {
          body { background: #050B1B; color: #E2E8F0; }
          .wrap { background: #0B1430; border-color: #1E293B; }
          th { background: #0F1A38; color: #94A3B8; }
          td { border-bottom-color: #1E293B; }
          tr:hover td { background: #11203F; }
        }
      </style>
    </head>
    <body>
      <div class="wrap">
        <header>
          <h1>XML Sitemap
            <span class="count-chip">
              <xsl:value-of select="count(sm:urlset/sm:url)"/>
              <xsl:text> URLs</xsl:text>
            </span>
          </h1>
          <p>This is a human-readable view of <code>/sitemap.xml</code>. Search engines crawl the raw XML — this stylesheet is presentation only.</p>
        </header>
        <table>
          <thead>
            <tr><th>#</th><th>URL</th><th>Last Modified</th><th>Frequency</th><th>Priority</th></tr>
          </thead>
          <tbody>
            <xsl:for-each select="sm:urlset/sm:url">
              <tr>
                <td><xsl:value-of select="position()"/></td>
                <td class="url"><a href="{sm:loc}" target="_blank" rel="noopener"><xsl:value-of select="sm:loc"/></a></td>
                <td class="date"><xsl:value-of select="sm:lastmod"/></td>
                <td class="freq"><xsl:value-of select="sm:changefreq"/></td>
                <td class="priority">
                  <xsl:variable name="p" select="sm:priority"/>
                  <span>
                    <xsl:attribute name="class">
                      prio-pill
                      <xsl:choose>
                        <xsl:when test="$p &gt;= 0.8"> prio-high</xsl:when>
                        <xsl:when test="$p &gt;= 0.5"> prio-med</xsl:when>
                        <xsl:otherwise> prio-low</xsl:otherwise>
                      </xsl:choose>
                    </xsl:attribute>
                    <xsl:value-of select="$p"/>
                  </span>
                </td>
              </tr>
            </xsl:for-each>
          </tbody>
        </table>
      </div>
    </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
