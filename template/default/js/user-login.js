
$(function() {
    var jform = $('#form');
    jform.on('submit', function() {
        var jsubmit = jform.find('#submit');
        jsubmit.button('loading');
        jform.reset();
        var postdata = jform.serializeObject();
        $.xpost(jform.attr('action'), postdata, function(code, message) {
            if(code == 0) {
                jsubmit.button(message);
                var referer = String(postdata.referer || '');
                setTimeout(function(){
                    if (referer) {
                        window.location.href = referer;
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
