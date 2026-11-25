
$(function() {
    var jform = $('#form');
    jform.on('submit', function() {
        var jsubmit = jform.find('#submit');
        jform.reset();
        jsubmit.button('loading');
        var postdata = jform.serializeObject();
        $.xpost(jform.attr('action'), postdata, function(code, message) {
            if(code == 0) {
                jsubmit.button(message);
                var referer = String(postdata.referer || '').trim();
                var safeReferer = '';
                if (referer && !/[\x00-\x1f\x7f]/.test(referer)) {
                    try {
                        var parsed = new URL(referer, window.location.origin);
                        if (parsed.origin === window.location.origin && (parsed.protocol === 'http:' || parsed.protocol === 'https:')) {
                            safeReferer = parsed.href;
                        }
                    } catch (e) {}
                }
                setTimeout(function(){
                    if (safeReferer) {
                        window.location.href = safeReferer;
                    } else {
                        window.location.reload();
                    }
                }, 1000);
            } else if (xn.is_number(code)) {
                $.alert(message);
                jsubmit.button('reset');
            } else {
                jform.find('[name="' + code + '"]').fieldAlert(message).focus();
                jsubmit.button('reset');
            }
        });
        return false;
    });
});
