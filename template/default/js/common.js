$(function () {
    // 单一轮播 Swiper（合并移动端/桌面端）
    var bannerSwiperEl = document.querySelector('.bannerSwiper');
    if (bannerSwiperEl && typeof Swiper !== 'undefined') {
        var isMobile = window.innerWidth < 768;
        var bannerSwiper = new Swiper(".bannerSwiper", {
            autoplay: {
                delay: 4000,
                disableOnInteraction: false,
            },
            loop: true,
            effect: isMobile ? "slide" : "fade",
            fadeEffect: {
                crossFade: true,
            },
            speed: 600,
            pagination: {
                el: ".banner-pagination",
                clickable: true,
                bulletClass: "swiper-pagination-bullet",
                bulletActiveClass: "swiper-pagination-bullet-active",
                renderBullet: function (index, className) {
                    return '<span class="' + className + '"></span>';
                },
            },
            navigation: {
                nextEl: ".banner-next",
                prevEl: ".banner-prev",
            },
        });

        // 窗口大小变化时重新初始化
        var resizeTimer;
        $(window).on("resize", function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                var nowMobile = window.innerWidth < 768;
                if (nowMobile !== isMobile) {
                    isMobile = nowMobile;
                    bannerSwiper.destroy(true, true);
                    bannerSwiper = new Swiper(".bannerSwiper", {
                        autoplay: { delay: 4000, disableOnInteraction: false },
                        loop: true,
                        effect: isMobile ? "slide" : "fade",
                        fadeEffect: { crossFade: true },
                        speed: 600,
                        pagination: {
                            el: ".banner-pagination",
                            clickable: true,
                            bulletClass: "swiper-pagination-bullet",
                            bulletActiveClass: "swiper-pagination-bullet-active",
                        },
                        navigation: {
                            nextEl: ".banner-next",
                            prevEl: ".banner-prev",
                        },
                    });
                }
            }, 300);
        });
    }

    // 搜索框打开搜索详情
    $("#searchInput").on("focus", function () {
        $("#searchWrap").css("display", "flex");
        // 如果是移动端，则隐藏logo以及其他元素
        if ($(window).width() < 720) {
        $("#logo").addClass("hidden");
        $("#vip").addClass("hidden");
        $("#back").removeClass("hidden");
        }
    });

    $("#back").on("click", function () {
        $("#logo").removeClass("hidden");
        $("#vip").removeClass("hidden");
        $("#back").addClass("hidden");
        $("#searchWrap").css("display", "none");
    });

    $(document).on("click", function (e) {
        const target = e.target;

        if (
        $(window).width() >= 720 &&
        !target.closest("#searchWrap") &&
        !target.closest("#searchInput")
        ) {
        $("#searchWrap").css("display", "none");
        }
    });

    $(window).on("scroll", function () {
        const scrollTop = $(this).scrollTop();
        if (scrollTop >= 200) {
        $("#header").removeClass("md:bg-transparent");
        $("#backTop").removeClass("d-none");
        } else {
        $("#header").addClass("md:bg-transparent");
        $("#backTop").addClass("d-none");
        }
    });

    $("#backTop").on("click", function () {
        $("html, body").animate({ scrollTop: 0 }, 200);
    });

    function moveExtraTabs(deep) {
        var fixedWidth = 68.4375;
        var moreDropdown = $("#moreDropdown");
        var moreDropdownWrap = $("#moreDropdownWrap");
        var moreMenu = $("#moreMenu");

        // 获取所有导航项，但排除管理员链接（它们应该始终显示）
        var allMenuTabs = $("#menu > .header-tab").filter(function() {
            var text = $(this).text().trim();
            return text !== "后台管理" && text !== "退出登录";
        });

        if (allMenuTabs.length === 0) return;

        // 计算导航项的总宽度
        var totalWidth = 0;
        allMenuTabs.each(function() {
            totalWidth += $(this).outerWidth(true);
        });

        // 如果导航项太多，移动到下拉菜单
        var menuContainer = $("#menu");
        var containerWidth = menuContainer.width();
        var moreBtnWidth = moreMenu.outerWidth(true);
        var availableWidth = containerWidth - moreBtnWidth - 50;

        var currentWidth = 0;
        var shouldMove = false;
        var tabsToMove = [];

        allMenuTabs.each(function(index) {
            var tabWidth = $(this).outerWidth(true);
            if (currentWidth + tabWidth > availableWidth) {
                shouldMove = true;
            }
            if (shouldMove) {
                tabsToMove.push(this);
            }
            currentWidth += tabWidth;
        });

        // 移动超出的导航项
        if (tabsToMove.length > 0) {
            $(tabsToMove).each(function() {
                var $tab = $(this);
                var dropdownItem = $("<div>", {
                    class: "w-32 flex flex-col cursor-pointer py-2 items-center",
                });
                var a = $("<a>", {
                    class: "text-yellow-hover transition-colors duration-300 truncate text-white",
                    text: $tab.text(),
                    href: $tab.find('a').attr('href') || $tab.attr('href'),
                });
                dropdownItem.append(a);
                moreDropdownWrap.prepend(dropdownItem);
                $tab.remove();
            });
        }

        // 如果下拉菜单中有项目，恢复一些项目
        if (!deep && moreDropdownWrap.children().length > 0) {
            // 检查是否可以恢复一个项目
            var firstDropdownItem = moreDropdownWrap.children().first();
            var canRestore = false;
            var testItem = $("<a>", {
                class: "flex items-center cursor-pointer header-tab group relative px-2 whitespace-nowrap",
                text: firstDropdownItem.text(),
            });
            menuContainer.append(testItem);
            
            var newTotalWidth = 0;
            $("#menu > .header-tab").filter(function() {
                var text = $(this).text().trim();
                return text !== "后台管理" && text !== "退出登录";
            }).each(function() {
                newTotalWidth += $(this).outerWidth(true);
            });
            
            if (newTotalWidth < availableWidth) {
                canRestore = true;
            }
            
            testItem.remove();
            
            if (canRestore) {
                var item = moreDropdownWrap.children().first();
                var menuItem = $("<a>", {
                    class: "flex items-center cursor-pointer header-tab group relative px-2 whitespace-nowrap",
                    href: item.find("a").attr("href"),
                });
                var span = $("<span>", {
                    class: "text-yellow-hover transition-all duration-300 text-white group-hover:text-yellow-500 group-hover:scale-105",
                    text: item.text(),
                });
                menuItem.append(span);
                menuItem.append('<div class="absolute bottom-0 left-0 right-0 h-0.5 bg-yellow-500 scale-x-0 group-hover:scale-x-100 transition-transform duration-300 origin-left"></div>');
                moreMenu.before(menuItem);
                item.remove();
                moveExtraTabs(true);
            }
        }

        // 如果下拉菜单中有内容，显示更多按钮，否则隐藏
        if (moreDropdownWrap.children().length > 0) {
        moreMenu.show();
        } else {
        moreMenu.hide();
        }
    }

    // 初始调用
    moveExtraTabs();

    // 监听窗口大小变化事件
    $(window).on("resize", function () {
        moveExtraTabs();
    });

    // 显示和隐藏更多下拉菜单
    $("#moreMenu").click(function () {
        $("#moreDropdown").toggleClass("hidden");
    });

    // 如果需要点击外部关闭下拉菜单，可以添加以下代码
    $(document).click(function (event) {
        if (!$(event.target).closest("#more-button, .more-dropdown").length) {
        $(".more-dropdown").hide();
        }
    });

    // play页
    // 简介展示
    $("#openVideoIntro").on("click", function () {
        $("#videoInfo").hide();
        $("#videoIntro").show();
    });
    // 简介隐藏
    $("#videoIntroBack").on("click", function () {
        $("#videoInfo").show();
        $("#videoIntro").hide();
    });
    // 视频源左滑动
    $("#trackToLeft").on("click", function () {
        var videoSourceTrack = $("#videoSourceTrack");
        var scollLeft = videoSourceTrack.scrollLeft();
        var skewWidth = videoSourceTrack.find("#vodSource-1").outerWidth(true);
        videoSourceTrack.scrollLeft(scollLeft - skewWidth);
        if (videoSourceTrack.scrollLeft() <= 0) {
        $(this).find("img").addClass("transparent");
        }
        $("#trackToRight").find("img").removeClass("transparent");
    });
    // 视频源右滑动
    $("#trackToRight").on("click", function () {
        var videoSourceTrack = $("#videoSourceTrack");
        var scollLeft = videoSourceTrack.scrollLeft();
        var skewWidth = videoSourceTrack.find("#vodSource-1").outerWidth(true);
        videoSourceTrack.scrollLeft(scollLeft + skewWidth);
        if (
        videoSourceTrack.scrollLeft() >=
        videoSourceTrack[0].scrollWidth - videoSourceTrack[0].offsetWidth
        ) {
        $(this).find("img").addClass("transparent");
        }
        $("#trackToLeft").find("img").removeClass("transparent");
    });
    const videoLength = $("#videoList").children().length
    $("#episodeCountEnd").text(
    videoLength >= 20
        ? "20"
        : videoLength
    );
    $(".videoEndLeavl").text($("#episodeCountEnd").text());
    if (videoLength < 2) {
        $("#mobileVideoGroup-selected").hide()
    }
    $("#videoSelected").on("click", function () {
        $("#selectedList").toggleClass("hidden");
    });
    // 初始化pc视频播放列表
    function initVideoList() {
        // 偏移集数
        const skewLeave = 20;
        const selectedLength = Math.ceil(
        $("#videoList").children().length / skewLeave
        );
        if (selectedLength > 1) {
        // 大于20集的不显示
        $("#videoList").children().slice(skewLeave).hide();
        }
        for (let i = 0; i < selectedLength; i++) {
        const li = $("<li>", {
            class:
            i === 0
                ? "style_radioOptionCard__ON4rV style_selectedOptionDropdownGroup__Na2am"
                : "style_radioOptionCard__ON4rV cursor-pointer",
            style: "width: 118px; margin-bottom: 0px;",
        });

        const label = $("<label>", {
            for: "episodeGroup-0",
            class: "flex flex-row space-x-1 cursor-pointer",
        });

        const span = $("<span>", {
            class: "text-sm",
            text: i * 20 + 1,
        });

        const span2 = $("<span>", {
            class: "text-sm",
            text: "-",
        });

        let span3 = $("<span>", {
            class: "text-sm",
            text: selectedLength <= 1 ? $("#episodeCountEnd").text() : (i + 1) * 20,
        });
        if (i === selectedLength - 1) {
            const lastText = $("#videoList").children().last().find("a").text()
            const splitText = lastText.replace(/[^0-9]/ig, '')
            span3 = $("<span>", {
            class: "text-sm",
            text: splitText,
            });
        }

        const span4 = $("<span>", {
            class: "text-sm",
            text: "",
        });

        label.append(span, span2, span3, span4);

        li.append(label);

        li.on("click", function () {
            $(this).addClass("style_selectedOptionDropdownGroup__Na2am");
            $(this)
            .siblings()
            .removeClass("style_selectedOptionDropdownGroup__Na2am");

            const i = $(this).index();
            // 展示选中的集数
            $("#videoList").children().hide();
            $("#videoList")
            .children()
            .slice(i * skewLeave, (i + 1) * skewLeave)
            .show();

            // 隐藏选集列表
            $("#selectedList").toggleClass("hidden");
            // 更新选集下拉菜单展示
            $("#episodeCountStart").text(span.text());
            $("#episodeCountEnd").text(span3.text());
        });

        $("#selectedList").append(li);
        }
    }

    initVideoList();
    initMobileVideoList();

    //  初始化移动端视频列表
    function initMobileVideoList() {
        // 偏移集数
        const skewLeave = 20;
        const selectedLength = Math.ceil(
        $("#mobileVideoWrap").children().length / skewLeave
        );
        if (selectedLength > 1) {
        // 大于20集的不显示
        $("#mobileVideoWrap").children().slice(skewLeave).hide();
        $('#mobileModalVideoWrap').children().slice(skewLeave).hide();
        }
        for (let i = 0; i < selectedLength; i++) {
            const li = $("<li>", {
                class:
                i === 0
                    ? "style_radioOptionCard__ON4rV style_selectedOptionDetailsCard__aACQa"
                    : "style_radioOptionCard__ON4rV style_unselectedOptionDetailsCard__VSu96",
            });
            const label = $("<label>", {
                class: "flex flex-row space-x-1",
            });
        
            const span = $("<span>", {
                class: "text-sm",
                text: i * 20 + 1,
            });
        
            const span2 = $("<span>", {
                class: "text-sm",
                text: "-",
            });
        
            let span3 = $("<span>", {
                class: "text-sm",
                text: (selectedLength <= 1 ? $("#episodeCountEnd").text() : (i + 1) * 20) + '集',
            });
            if (i === selectedLength - 1) {
                const lastText = $("#mobileModalVideoWrap").children().last().find("a").text()
                const splitText = lastText.replace(/[^0-9]/ig, '')
                span3 = $("<span>", {
                class: "text-sm",
                text: splitText + '集',
                });
            }
        
            const span4 = $("<span>", {
                class: "text-sm",
                text: "",
            });
        
            label.append(span, span2, span3, span4);
        
            li.append(label);
            
            li.on("click", function () {
                $(this).removeClass("style_unselectedOptionDetailsCard__VSu96");
                $(this).addClass("style_selectedOptionDetailsCard__aACQa");
                $(this)
                .siblings()
                .removeClass("style_selectedOptionDetailsCard__aACQa")
                .addClass("style_unselectedOptionDetailsCard__VSu96");
        
                const i = $(this).index();
                // 展示选中的集数
                $("#mobileModalVideoWrap").children().hide();
                $("#mobileModalVideoWrap")
                .children()
                .slice(i * skewLeave, (i + 1) * skewLeave)
                .show();
                $("#mobileVideoWrap").children().hide();
                $("#mobileVideoWrap")
                .children()
                .slice(i * skewLeave, (i + 1) * skewLeave)
                .show();
                
                // 更新选集下拉菜单展示
                $(".videoStartLeavl").text(span.text().replace('集', ''));
                $(".videoEndLeavl").text(span3.text().replace('集', ''));
            });
            
            $('#modalVideoSelected').append(li)
        }
    }

    $("#mobileVideoGroup-selected").on("click", function () {
        $("#videoMobileList").show({ duration: 300 });
    });
    $("#videoMobileListModal").on("click", function () {
        $("#videoMobileList").hide({ duration: 300 });
    });

    $("#openMobileIntro").on("click", function () {
        $("#videoMobileIntro").show({ duration: 300 });
    });
    $("#videoMobileIntroModal").on("click", function () {
        $("#videoMobileIntro").hide({ duration: 300 });
    });
    $("#closeVideoMobileIntro").on("click", function () {
        $("#videoMobileIntro").hide({ duration: 300 });
    });

    $('.backPage').on('click', function () {
        window.history.back();
    })

    $('#download').hover(  
        function() {  
            $('#downloadbox').show();  
        },   
        function() {  
            $('#downloadbox').hide(); 
        }  
    );  

    $('.share-icn').click(function() {  
        event.stopPropagation(); 
        $('.share-box-bg').removeClass('hidden').addClass('block');  
        $('.share-box').removeClass('hidden').addClass('block');  
    }); 

    $(document).click(function(event) {  
        if (!$(event.target).closest('.share-box-center').length) {  
            $('.share-box-bg').removeClass('block').addClass('hidden');  
            $('.share-box').removeClass('block').addClass('hidden');  
        }  
    });  

    $('.copy-btn').click(function() {  
        const $tempInput = $('<input>');  
        $('body').append($tempInput);  
        $tempInput.val($('#share-text').text()).select();  
        document.execCommand('copy');  
        $tempInput.remove();  

        $('#message').fadeIn().delay(1000).fadeOut();  
        $('.share-box-bg').removeClass('block').addClass('hidden');  
        $('.share-box').removeClass('block').addClass('hidden');  
    });  

});
