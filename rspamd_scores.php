<?php
/**
 * RoundCube Rspamd Scores plugin
 *
 * @version 0.1
 * @author Harry Youd <harry@harryyoud.co.uk>
 */
class rspamd_scores extends rcube_plugin {

    const TOTAL_REGEX = '/^(?<config_name>[a-zA-Z0-9]*)\:\s(?<result>False|True)\s\[(?<total_score>-?[0-9]+\.[0-9]+)\s\/\s(?<limit>-?[0-9]+\.[0-9]+)\]/';
    const RULES_REGEX = '/^(?<rule>[A-Z0-9\_]+)\((?<score>-?[0-9]+\.[0-9]+)\)\[(?<reason>[^\]]*)\]/';
    const LOW = 'low';
    const MEDIUM = 'medium';
    const HIGH = 'high';
    const RULE_DESCRIPTIONS = __DIR__.'/score_symbols.json';

    private static $raw_header;

    function init() {
        $this->rc = rcmail::get_instance();
        $this->load_config();

        $this->add_hook('storage_init', array($this, 'storage_init'));
        $this->add_hook('messages_list', array($this, 'message_list'));
        $this->add_hook('message_headers_output', [$this, 'message_headers']);

        $this->register_action('plugin.rspamd_scores.show_breakdown', [$this, 'show_breakdown']);
        $this->include_stylesheet($this->local_skin_path() . '/spam_score.css');
        $this->include_script('spam_score.js');

        if ($this->rc->task === 'mail') {
            $this->rc->output->set_env('rspamd_scores_medium_limit', $this->rc->config->get('rspamd_scores_medium_limit'));
            $this->rc->output->set_env('rspamd_scores_high_limit', $this->rc->config->get('rspamd_scores_high_limit'));
            $this->rc->output->set_env('rspamd_scores_list_show_low', $this->rc->config->get('rspamd_scores_list_show_low'));
            $this->rc->output->set_env('rspamd_scores_list_show_medium', $this->rc->config->get('rspamd_scores_list_show_medium'));
            $this->rc->output->set_env('rspamd_scores_list_show_high', $this->rc->config->get('rspamd_scores_list_show_high'));
        } elseif ($this->rc->task === 'settings') {
            $this->add_hook('preferences_list', array($this, 'prefs_list'));
            $this->add_hook('preferences_save', array($this, 'prefs_save'));
        }
    }

    function storage_init($p) {
        $add_headers = strtoupper(join(' ', [$this->rc->config->get('rspamd_scores_header')]));
        if (isset($p['fetch_headers'])) {
            $p['fetch_headers'] .= ' ' . $add_headers;
        }
        else {
            $p['fetch_headers'] = $add_headers;
        }

        return $p;
    }

    function message_headers($p) {
        if (!($value = $p['headers']->get($this->rc->config->get('rspamd_scores_header')))) {
            return $p;
        }
        $header = $this->parse_header_total_only($value);
        if (empty($header['total'])) {
            return $p;
        }
        $total_score = floatval($header['total']['total_score']);
        $is_spam = $header['total']['result'];
        $p['output']['spam_score'] = [
            'title' => "Spam Score",
            'value' => sprintf('<a href="#" id="rspamd_total_score_chip" class="spam_total_score badge %s">%s (%.2F); %s</span>',
                $this->get_total_bounding($total_score), ucfirst($this->get_total_bounding($total_score)), $total_score, $is_spam ? 'spam' : 'not spam'),
            'html' => true,
        ];
        return $p;
    }

    function get_total_bounding(float $score): string {
        if ($score >= $this->rc->config->get('rspamd_scores_high_limit')) {
            return self::HIGH;
        }
        if ($score >= $this->rc->config->get('rspamd_scores_medium_limit')) {
            return self::MEDIUM;
        }
        return self::LOW;
    }

    function get_individual_bounding(float $score): string {
        if ($score > 0) {
            return self::HIGH;
        }
        if ($score < 0) {
            return self::LOW;
        }
        return self::MEDIUM;
    }

    function show_breakdown() {
        $uid = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_GP);

        if (!$uid) {
            exit;
        }

        $message = new rcube_message($uid);
        $this->raw_header = $message->get_header($this->rc->config->get('rspamd_scores_header'));

        if ($this->raw_header !== false) {
            $this->rc->output->add_handlers(['dialogcontent' => [$this, 'show_breakdown_html']]);
        } else {
            $this->rc->mail->output->show_message('messageopenerror', 'error');
        }

        $this->rc->output->send('dialog');
    }

    function show_breakdown_html() {
        $f = file_get_contents(self::RULE_DESCRIPTIONS);
        $descriptions = json_decode($f, true);

        $rule_table = new html_table(['class' => 'table-sm']);
        $rule_table->add_header([], 'Score');
        $rule_table->add_header([], 'Rule');
        $rule_table->add_header([], 'Description');
        $header = $this->parse_header($this->raw_header);

        foreach ($header['rules'] as $rule) {
            $description = "";
            if (isset($descriptions[$rule['rule']]) && !is_null($descriptions[$rule['rule']])) {
                $description = $descriptions[$rule['rule']];
            }
            $rule_table->add_row();
            $rule_table->add([], sprintf('<span class="spam_breakdown_score badge %s">%.2F</span>',
                $this->get_individual_bounding($rule['score']), $rule['score']));
            $rule_table->add([], $rule['rule']);
            if (empty($rule['reason'])) {
                $rule_table->add([], $description);
            } elseif (empty($description)) {
                $rule_table->add([], sprintf('<small class="text-muted">[%s]</small>', $rule['reason']));
            } else {
                $rule_table->add([], sprintf('%s<br /><small class="text-muted">[%s]</small>', $description, $rule['reason']));
            }
        }
        $explanation = html::p([], sprintf('Scored <span class="spam_breakdown_score badge %s">%.2F</span> (out of <span class="spam_breakdown_score badge badge-dark">%.2F</span> total) using %s configuration (spam result = %s)',
            $this->get_total_bounding($header['total']['total_score']), $header['total']['total_score'], $header['total']['limit'],
            $header['total']['config_name'], $header['total']['result'] ? 'yes': 'no'));
        return html::div([], $explanation . $rule_table->show());
    }

    function parse_header_total_only(?string $raw_header) {
        if (is_null($raw_header))
            return null;
        return ['total' => $this->_parse_header_total(explode(";", $raw_header)[0])];
    }

    function _parse_header_total(string $line) {
        $matches = [];
        preg_match(self::TOTAL_REGEX, trim($line), $matches);
        if (empty($matches)) {
            return [];
        }
        return [
            'config_name' => $matches['config_name'],
            'result' => $matches['config_name'] === 'True',
            'total_score' => floatval($matches['total_score']),
            'limit' => floatval($matches['limit']),
        ];
    }

    function parse_header($raw_header) {
        $total = [];
        $rules = [];
        foreach (explode(";", $raw_header) as $idx => $line) {
            if ($idx === 0) {
                $total = $this->_parse_header_total($line);
                continue;
            }
            preg_match(self::RULES_REGEX, trim($line), $matches);
            if (empty($matches)) {
                continue;
            }
            $rules[] = [
                'rule' => $matches['rule'],
                'score' => $matches['score'],
                'reason' => $matches['reason'],
            ];
        }
        return ['total' => $total, 'rules' => $rules];
    }

    function message_list(array $args) {
        foreach($args['messages'] as $message) {
            $total = $this->parse_header_total_only($message->others['x-spamd-result'])['total']['total_score'];
            $message->list_flags['extra_flags']['spam_score'] = [
                'score' => $total,
                'level' => $this->get_total_bounding($total ?? 0),
            ];
        }
    }

    function prefs_list($args) {
        if ($args['section'] != 'mailbox') {
            return $args;
        }

        $args['blocks']['rspamd_scores'] = [];
        $args['blocks']['rspamd_scores']['name'] = "Spam Score Badges";

        $args = $this->add_checkbox($args, 'rspamd_scores_list_show_low', 'Show spam score when score is low');
        $args = $this->add_checkbox($args, 'rspamd_scores_list_show_medium', 'Show spam score when score is medium');
        $args = $this->add_checkbox($args, 'rspamd_scores_list_show_high', 'Show spam score when score is high');

        return $args;
    }

    function add_checkbox($args, $key, $label) {
        if (in_array($key, (array) $this->rc->config->get('dont_override', []))) {
            return $args;
        }
        $args['blocks']['rspamd_scores']['options'][$key] = [
            'title' => $label,
            'content' => (new html_checkbox([
                'name' => $key,
                'id' => $key,
                'value' => 1
            ]))->show($this->rc->config->get($key)),
        ];
        return $args;
    }

    function save_checkbox($args, $key) {
        if (in_array($key, (array) $this->rc->config->get('dont_override', []))) {
            return $args;
        }
        $args['prefs'][$key] = rcube_utils::get_input_value($key, rcube_utils::INPUT_POST) ? true : false;
        return $args;
    }

    function prefs_save($args) {
        if ($args['section'] != 'mailbox') {
            return $args;
        }
        $args = $this->save_checkbox($args, 'rspamd_scores_list_show_low');
        $args = $this->save_checkbox($args, 'rspamd_scores_list_show_medium');
        $args = $this->save_checkbox($args, 'rspamd_scores_list_show_high');
        return $args;
    }
}
