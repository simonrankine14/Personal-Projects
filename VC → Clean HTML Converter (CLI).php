{\rtf1\ansi\ansicpg1252\cocoartf2865
\cocoatextscaling0\cocoaplatform0{\fonttbl\f0\fswiss\fcharset0 Helvetica;}
{\colortbl;\red255\green255\blue255;}
{\*\expandedcolortbl;;}
\paperw11900\paperh16840\margl1440\margr1440\vieww25400\viewh12340\viewkind0
\pard\tx720\tx1440\tx2160\tx2880\tx3600\tx4320\tx5040\tx5760\tx6480\tx7200\tx7920\tx8640\pardirnatural\partightenfactor0

\f0\fs24 \cf0 <?php\
/**\
 * Plugin Name: VC \uc0\u8594  Clean HTML Converter (CLI)\
 * Description: Batch-convert WPBakery/VC post_content to clean HTML via WP-CLI.\
 * Author: Simon Rankine\
 * Version: 1.0.1\
 */\
\
if (defined('WP_CLI') && WP_CLI) \{\
    class VC2Clean_Command extends WP_CLI_Command \{\
\
        /**\
         * Run conversion over posts.\
         */\
        public function run($args, $assoc_args) \{\
            $post_type = $assoc_args['post_type'] ?? 'post';\
            $status    = $assoc_args['status'] ?? 'any';\
            $ids_csv   = $assoc_args['ids'] ?? '';\
            $offset    = isset($assoc_args['offset']) ? (int)$assoc_args['offset'] : 0;\
            $limit     = isset($assoc_args['limit']) ? (int)$assoc_args['limit'] : 100;\
            $dry_run   = isset($assoc_args['dry-run']);\
            $restore   = isset($assoc_args['restore']);\
\
            $query_args = [\
                'post_type'      => $post_type,\
                'post_status'    => $status === 'any' ? ['publish','draft','pending','future','private'] : explode(',', $status),\
                'posts_per_page' => $limit,\
                'offset'         => $offset,\
                'orderby'        => 'ID',\
                'order'          => 'ASC',\
                'fields'         => 'all',\
            ];\
\
            if (!empty($ids_csv)) \{\
                $ids = array_map('intval', explode(',', $ids_csv));\
                $query_args = [\
                    'post_type'   => $post_type,\
                    'post__in'    => $ids,\
                    'post_status' => ['publish','draft','pending','future','private'],\
                    'orderby'     => 'post__in',\
                    'posts_per_page' => -1,\
                ];\
            \}\
\
            $q = new WP_Query($query_args);\
            $count = 0;\
\
            foreach ($q->posts as $post) \{\
                $orig = $post->post_content;\
\
                if ($restore) \{\
                    $backup = get_post_meta($post->ID, '_vc2clean_backup', true);\
                    if ($backup === '') \{\
                        WP_CLI::warning("\{$post->ID\}: No backup meta to restore.");\
                        continue;\
                    \}\
                    if ($dry_run) \{\
                        WP_CLI::log("DRY-RUN restore \{$post->ID\} (\{$post->post_title\})");\
                    \} else \{\
                        wp_update_post([\
                            'ID'           => $post->ID,\
                            'post_content' => $backup,\
                        ]);\
                        WP_CLI::success("Restored from backup \uc0\u8594  \{$post->ID\} (\{$post->post_title\})");\
                    \}\
                    $count++;\
                    continue;\
                \}\
\
                $converted = $this->convert_content($orig);\
\
                if ($converted === $orig) \{\
                    WP_CLI::log("\{$post->ID\}: No change.");\
                    continue;\
                \}\
\
                if ($dry_run) \{\
                    WP_CLI::log("DRY-RUN would update \{$post->ID\} (\{$post->post_title\})");\
                \} else \{\
                    if ('' === get_post_meta($post->ID, '_vc2clean_backup', true)) \{\
                        update_post_meta($post->ID, '_vc2clean_backup', $orig);\
                    \}\
                    wp_update_post([\
                        'ID'           => $post->ID,\
                        'post_content' => $converted,\
                    ]);\
                    WP_CLI::success("Updated \{$post->ID\} (\{$post->post_title\})");\
                \}\
\
                $count++;\
            \}\
\
            WP_CLI::success("Processed \{$count\} posts.");\
        \}\
\
        private function convert_content($content) \{\
            // 1) Normalise newlines\
            $c = str_replace(["\\r\\n", "\\r"], "\\n", $content);\
\
            // 2) Normalise smart quotes & non-breaking spaces\
            $c = strtr($c, [\
                "\'93" => '"', "\'94" => '"',\
                "\'91" => "'", "\'92" => "'",\
                "\\xC2\\xA0" => ' ',\
            ]);\
\
            // 3) Remove [gallery ...]\
            $c = preg_replace('/\\[gallery\\b[^\\]]*\\]/i', '', $c);\
\
            // 4) [vc_custom_heading]\
            $c = preg_replace_callback('/\\[vc_custom_heading\\b([^\\]]*)\\]/i', function($m)\{\
                $attrs = $this->parse_attrs($m[1]);\
                $text  = isset($attrs['text']) ? wp_kses_post($attrs['text']) : '';\
                if ($text === '') return '';\
                return "<h2>\{$text\}</h2>";\
            \}, $c);\
\
            // 5) [vc_btn]\
            $c = preg_replace_callback('/\\[vc_btn\\b([^\\]]*)\\]/i', function($m)\{\
                $a = $this->parse_attrs($m[1]);\
                $url = '';\
                if (!empty($a['url'])) $url = $a['url'];\
                elseif (!empty($a['link'])) $url = $this->parse_vc_link_field($a['link'])['url'] ?? '';\
                $title = !empty($a['title']) ? $a['title'] : ($url ?: '');\
                if (!$url) return '';\
                return '<p><a href="'.esc_url_raw($url).'">'.wp_kses_post($title).'</a></p>';\
            \}, $c);\
\
            // 6) [vc_single_image]\
            $c = preg_replace_callback('/\\[vc_single_image\\b([^\\]]*)\\]/i', function($m)\{\
                $a = $this->parse_attrs($m[1]);\
                $src = '';\
                foreach (['src','image','img_src','link','img_link'] as $k) \{\
                    if (!empty($a[$k]) && filter_var($a[$k], FILTER_VALIDATE_URL)) \{\
                        $src = $a[$k];\
                        break;\
                    \}\
                \}\
                if (!$src && !empty($a['image']) && preg_match('/\\d+/', $a['image'], $mId)) \{\
                    $id  = intval($mId[0]);\
                    $url = wp_get_attachment_image_url($id, 'full');\
                    if ($url) $src = $url;\
                \}\
                if (!$src) return '';\
                return '<p><img src="'.esc_url_raw($src).'" alt=""></p>';\
            \}, $c);\
\
            // 7) [vc_video]\
            $c = preg_replace_callback('/\\[vc_video\\b([^\\]]*)\\]/i', function($m)\{\
                $a = $this->parse_attrs($m[1]);\
                $url = $a['link'] ?? ($a['url'] ?? '');\
                if (!$url) return '';\
                return '<p><a href="'.esc_url_raw($url).'">'.esc_html($url).'</a></p>';\
            \}, $c);\
\
            // 8) Unwrap VC containers\
            foreach (['vc_row','vc_row_inner','vc_column','vc_column_inner','vc_column_text','vc_tta_accordion','vc_tta_tabs','vc_tta_section','vc_tta_tour','vc_toggle'] as $tag) \{\
                $c = preg_replace('/\\['.$tag.'\\b[^\\]]*\\](.*?)\\[\\/'.$tag.'\\]/is', '$1', $c);\
            \}\
\
            // 9) Remove purely decorative VC tags\
            foreach (['vc_empty_space','vc_separator','vc_zigzag','vc_icon','vc_single_icon','vc_widget_sidebar'] as $tag) \{\
                $c = preg_replace('/\\['.$tag.'\\b[^\\]]*\\]/i', '', $c);\
            \}\
\
            // 10) [ultimate_heading]\
            $c = preg_replace_callback('/\\[ultimate_heading\\b([^\\]]*)\\](.*?)\\[\\/ultimate_heading\\]/is', function($m)\{\
                $a = $this->parse_attrs($m[1]);\
                $inner = trim(wp_kses_post($m[2]));\
                $tag = strtolower($a['heading_tag'] ?? 'h2');\
                if (!preg_match('/^h[1-6]$/', $tag)) $tag = 'h2';\
                $text = $a['main_heading'] ?? ($a['text'] ?? $inner);\
                $text = trim(wp_kses_post($text));\
                if ($text === '') return '';\
                return "<\{$tag\}>\{$text\}</\{$tag\}>";\
            \}, $c);\
\
            // 11) self-closing [ultimate_heading ... /]\
            $c = preg_replace_callback('/\\[ultimate_heading\\b([^\\]]*)\\s*\\/\\]/i', function($m)\{\
                $a = $this->parse_attrs($m[1]);\
                $tag = strtolower($a['heading_tag'] ?? 'h2');\
                if (!preg_match('/^h[1-6]$/', $tag)) $tag = 'h2';\
                $text = $a['main_heading'] ?? ($a['text'] ?? '');\
                $text = trim(wp_kses_post($text));\
                if ($text === '') return '';\
                return "<\{$tag\}>\{$text\}</\{$tag\}>";\
            \}, $c);\
\
            // 12) [dt_divider] \uc0\u8594  remove\
            $c = preg_replace('/\\[dt_divider\\b[^\\]]*\\](?:\\s*\\[\\/dt_divider\\])?/i', '', $c);\
            $c = preg_replace('/\\[dt_divider\\b[^\\]]*\\/\\]/i', '', $c);\
\
            // 13) [cq_vc_hovercardv2]\
            $c = preg_replace_callback('/\\[cq_vc_hovercardv2\\b([^\\]]*)\\](?:.*?)\\[\\/cq_vc_hovercardv2\\]/is', function($m)\{\
                $a = $this->parse_attrs($m[1]);\
                $url_raw = $a['imagelink'] ?? '';\
                $url_dec = $url_raw ? rawurldecode($url_raw) : '';\
                $url = '';\
                if ($url_dec) \{\
                    if (stripos($url_dec, 'url:') === 0) \{\
                        $parsed = $this->parse_vc_link_field($url_dec);\
                        $url = $parsed['url'] ?? '';\
                    \} else \{\
                        $url = $url_dec;\
                    \}\
                \}\
                $title = trim(wp_kses_post($a['title'] ?? ($url ?: '')));\
                if (!$url || !$title) return '';\
                return '<p><a href="'.esc_url_raw($url).'">'.esc_html($title).'</a></p>';\
            \}, $c);\
\
            // 14) Generic [vc_*] removals\
            $c = preg_replace('/\\[(vc_[a-z0-9_]+)\\b[^\\]]*\\](.*?)\\[\\/\\1\\]/is', '$2', $c);\
            $c = preg_replace('/\\[vc_[a-z0-9_]+[^\\]]*\\]/i', '', $c);\
\
            // 15) Clean up\
            $c = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));\
            $c = preg_replace('/(?:\\s*<br\\s*\\/?>\\s*)\{3,\}/i', "<br>\\n<br>\\n", $c);\
            $c = $this->ensure_paragraphs($c);\
            $c = preg_replace('/<p>\\s*<\\/p>/i', '', $c);\
            $c = preg_replace('/<div>\\s*<\\/div>/i', '', $c);\
            $c = preg_replace('/<span>\\s*<\\/span>/i', '', $c);\
            $c = preg_replace("/\\n\{3,\}/", "\\n\\n", $c);\
\
            return trim($c);\
        \}\
\
        private function parse_attrs($raw) \{\
            $attrs = [];\
            if (preg_match_all('/(\\w+)\\s*=\\s*"([^"]*)"/', $raw, $m, PREG_SET_ORDER)) \{\
                foreach ($m as $pair) \{\
                    $attrs[strtolower($pair[1])] = html_entity_decode($pair[2], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));\
                \}\
            \}\
            if (preg_match_all("/(\\w+)\\s*=\\s*'([^']*)'/", $raw, $m2, PREG_SET_ORDER)) \{\
                foreach ($m2 as $pair) \{\
                    $attrs[strtolower($pair[1])] = html_entity_decode($pair[2], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));\
                \}\
            \}\
            return $attrs;\
        \}\
\
        private function parse_vc_link_field($val) \{\
            $out = [];\
            $parts = explode('|', $val);\
            foreach ($parts as $p) \{\
                if (strpos($p, ':') === false) continue;\
                list($k,$v) = array_map('trim', explode(':', $p, 2));\
                if ($k !== '') $out[$k] = $v;\
            \}\
            return $out;\
        \}\
\
        private function ensure_paragraphs($html) \{\
            $lines = preg_split("/\\n/", $html);\
            $out = [];\
            foreach ($lines as $line) \{\
                $trim = trim($line);\
                if ($trim === '') \{ $out[] = ''; continue; \}\
                if (preg_match('/^\\s*<(h[1-6]|p|ul|ol|li|blockquote|pre|iframe|img|table|thead|tbody|tr|td|th|figure|figcaption|hr)\\b/i', $trim)) \{\
                    $out[] = $trim;\
                \} else \{\
                    if (filter_var($trim, FILTER_VALIDATE_URL)) \{\
                        $out[] = '<p><a href="'.esc_url_raw($trim).'">'.$trim.'</a></p>';\
                    \} else \{\
                        $out[] = '<p>'.$trim.'</p>';\
                    \}\
                \}\
            \}\
            return implode("\\n", $out);\
        \}\
    \}\
\
    WP_CLI::add_command('vc2clean', 'VC2Clean_Command');\
\}\
}