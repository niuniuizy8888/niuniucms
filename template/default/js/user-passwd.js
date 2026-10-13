
$(function() {
    var body = $('body');
    body.on('submit', '#form', function () {
        var jthis = $(this);
        var jsubmit = jthis.find('#submit');
        jsubmit.button('loading');
        jthis.reset();
        var postdata = jthis.serializeObject();
        $.xpost(jthis.attr('action'), postdata, function (code, message) {
            if (code == 0) {
                $.alert(message);
                jsubmit.button(message).delay(3000).button('reset');
            } else if (xn.is_number(code)) {
                alert(message);
                jsubmit.button('reset');
            } else {
                jthis.find('[name="' + code + '"]').fieldAlert(message).focus();
                jsubmit.button('reset');
            }
        });
        return false;
    });
});
