jQuery(function ($) {
    function doTest(platform) {
        $.post(ajax.url, {
            _ajax_nonce: ajax.testNonce, action: "wpma_test",
            platform: platform
        }, function (data) {
            console.log(data);
        })
    }

    function doPublish(platform) {
        $.post(ajax.url, {
            _ajax_nonce: ajax.publishNonce, action: "wpma_publish_manually",
            platform: platform
        }, function (data) {
            console.log(data);
        })
    }


    $('#test_discord').on('click', function () {
        doTest("discord");
    })
    $('#test_at_proto').on('click', function () {
        doTest("at_proto");
    })
    $('#test_mastodon').on('click', function () {
        doTest("mastodon");
    })
    $('#publish_mastodon').on('click', function () {
        doPublish("mastodon");
    })
    $('#publish_at_proto').on('click', function () {
        doPublish("at_proto");
    })
    $('#publish_discord').on('click', function () {
        doPublish("discord");
    })
})

