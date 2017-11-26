function initAdminKit() {
    $('#form_buttons').prepend(
        $('<button class="btn btn-secondary mr-2" type="button">Clear seleciton</button>')
            .click(function(){
                $('form input:checkbox')
                    .prop("checked", false)
                    .parent('div').removeClass('checked');
            }),
        $('<button class="btn btn-secondary mr-2" type="button">Select all</button>')
            .click(function(){
                $('form input:checkbox')
                    .prop("checked", true)
                    .parent('div').addClass('checked');;
            })
    )

    $('form').on("click", "div.record", function(){
        var checkbox = $(this).find("input:checkbox")
        var value = checkbox.prop("checked");
        
        checkbox.prop("checked", !value)
        $(this).toggleClass('checked', !value);
    })
}
