const rcm_rspamd_scores_breakdown = () => {
  var props = {_uid: rcmail.env.uid, _mbox: rcmail.env.mailbox, _framed: 1},
  dialog = $('<iframe>').attr({id: 'spamscoreframe', src: rcmail.url('plugin.rspamd_scores.show_breakdown', props)});
  console.log(rcmail.env.uid)

  rcmail.simple_dialog(dialog, "Spam Score Breakdown", null, {
    cancel_button: 'close',
    height: 600
  });
}

document.addEventListener("DOMContentLoaded", function(event) {
  rcmail.addEventListener('init', function(evt) {
    rcmail.register_command('plugin.rspamd_scores.open_breakdown_dialog', rcm_rspamd_scores_breakdown, rcmail.env.uid);
    $('#rspamd_total_score_chip').on('click', () => rcmail.command('plugin.rspamd_scores.open_breakdown_dialog'));
  });
  rcmail.addEventListener('insertrow', function(evt) {
    if (evt.row.flags.spam_score.score === null)
      return
    if (!rcmail.env[`rspamd_scores_list_show_${evt.row.flags.spam_score.level}`])
      return
    $(`<span class="spam_score skip-on-drag">
        <span class="spam_breakdown_score spam_messagelist_total_score badge badge-light ${evt.row.flags.spam_score.level}" style="line-height: normal;">${evt.row.flags.spam_score.score}</span>
        </span>`
    ).appendTo($(evt.row.obj).find('span.subject'))
  })
})
