/*
 * 命名澄清：本文件名为 admin-layout，但实际仅被前台用户中心页面
 * （template/default/html/user/foot.html）加载——用户中心复用后台 sash 布局样式
 * （static/css/app.css），后台自身（core/admin/html/foot.html）并不加载本脚本。
 * 保留文件名以兼容模板引用，勿按字面理解其用途。
 */
"use strict";

let currentWidth;
(function () {

    currentWidth = [window.innerWidth];
    var slideMenu = $('.side-menu');

    // Toggle Sidebar
    $(document).on('click', '[data-bs-toggle="sidebar"]', function (event) {
        event.preventDefault();
        // $('.app').toggleClass('sidenav-toggled');
        if ($('.app').hasClass('sidenav-toggled')) {
            $('.app').removeClass('sidenav-toggled');
            if ((document.body.classList.contains("double-menu") || document.body.classList.contains("double-menu-tabs")) && !document.body.classList.contains('horizontal')) {
                if (document.querySelector('.slide-menu') && window.innerWidth >= 992) {
                    let slidemenu = document.querySelectorAll('.slide-menu');
                    slidemenu.forEach(e => {
                        if (e.classList.contains('double-menu-active')) {
                            e.classList.remove('double-menu-active')
                        }
                    })
                    let sidemenuActive = document.querySelector('.side-menu__item.active');
                    if (sidemenuActive?.nextElementSibling) {
                        let submenu = sidemenuActive.nextElementSibling;
                        submenu.classList.add('double-menu-active');
                        document.body.classList.remove('sidenav-toggled');
                    }
                    else {
                        document.body.classList.add('sidenav-toggled');
                    }
                }
            }
        }
        else {
            $('.app').addClass('sidenav-toggled');
            if (innerWidth >= 992) {
                if ((document.body.classList.contains("double-menu") || document.body.classList.contains("double-menu-tabs")) && !document.body.classList.contains('horizontal')) {
                    if (document.querySelector('.slide-menu')) {
                        let slidemenu = document.querySelectorAll('.slide-menu');
                        slidemenu.forEach(e => {
                            if (e.classList.contains('double-menu-active')) {
                                e.classList.remove('double-menu-active')
                            }
                        })
                    }
                }
            }
        }
    });

    responsive();


    var toggleSidebar = function () {
        var w = $(window);
        if (w.outerWidth() <= 1024) {
            $("body").addClass("sidebar-gone");
            $(document).off("click", "body").on("click", "body", function (e) {
                if ($(e.target).hasClass('sidebar-show') || $(e.target).hasClass('search-show')) {
                    $("body").removeClass("sidebar-show");
                    $("body").addClass("sidebar-gone");
                    $("body").removeClass("search-show");
                }
            });
        } else {
            $("body").removeClass("sidebar-gone");
        }
    }
    toggleSidebar();
    $(window).resize(toggleSidebar);
    //sticky-header
    $(window).on("scroll", function (e) {
        if ($(window).scrollTop() >= 70) {
            $('.app-header').addClass('fixed-header');
            $('.app-header').addClass('visible-title');
        } else {
            $('.app-header').removeClass('fixed-header');
            $('.app-header').removeClass('visible-title');
        }
    });

    $(window).on("scroll", function (e) {
        if ($(window).scrollTop() >= 70) {
            $('.horizontal-main').addClass('fixed-header');
            $('.horizontal-main').addClass('visible-title');
        } else {
            $('.horizontal-main').removeClass('fixed-header');
            $('.horizontal-main').removeClass('visible-title');
        }
    });
    //p-scroll
    
    const ps = new PerfectScrollbar('.app-sidebar', {
        useBothWheelAxes: true,
        suppressScrollX: true,
        suppressScrollY: false,
    });

    //sticky-header
    $(window).on("scroll", function (e) {
        if ($(window).scrollTop() >= 70) {
            $('.app-header').addClass('fixed-header');
            $('.app-header').addClass('visible-title');
        }
        else {
            $('.app-header').removeClass('fixed-header');
            $('.app-header').removeClass('visible-title');
        }
    });


    HorizontalHovermenu();

    // for Icon-text Menu	
    //icontext(); 	

    // default layout	
    hovermenu();

    ActiveSubmenu();
})();

function responsive() {
    
    const mediaQuery = window.innerWidth;
    currentWidth.push(mediaQuery);
    if (currentWidth.length > 2) { currentWidth.shift() }
    if (currentWidth.length > 1) {
        if ((currentWidth[currentWidth.length - 1] < 992) && (currentWidth[currentWidth.length - 2] >= 992)) {
            // less than 992
        }

        if ((currentWidth[currentWidth.length - 1] >= 992) && (currentWidth[currentWidth.length - 2] < 992)) {
            // greater than 992

            if (document.body.classList.contains("double-menu") || document.body.classList.contains("double-menu-tabs")) {
                document.body.classList.remove("sidenav-toggled");
            }
        }
    }
}

function hovermenu() {

    $(".app-sidebar").hover(function () {
        if ($('.app').hasClass('sidenav-toggled')) {
            $('.app').addClass('sidenav-toggled-open');
        }
    }, function () {
        if ($('.app').hasClass('sidenav-toggled')) {
            $('.app').removeClass('sidenav-toggled-open');
        }
    });
}// ______________ICON-OVERLAY JS start
function iconoverlay() {

    $(document).on('click', ".app-content", function (event) {
        $('body').removeClass('sidenav-toggled-open');
    });

    //Mobile menu 
    var alterClass = function () {
        var ww = document.body.clientWidth;
        if (ww < 992) {
            $('body').removeClass('sidenav-toggled');
        } else if (ww >= 991 && !(document.querySelector('.horizontal') !== null)) {
            $('body').addClass('sidenav-toggled');
        };
    };
    $(window).resize(function () {
        alterClass();
    });
    //Fire it when the page first loads:
    alterClass();

}

// ______________ICON-OVERLAY JS end

// ______________ICON-TEXT JS start
function icontext() {
    $(".app-sidebar").off("mouseenter mouseleave");

    $(document).on('click', ".app-sidebar", function (event) {
        if ($('body').hasClass('sidenav-toggled') == true) {
            $('body').addClass('sidenav-toggled-open');
        }
    });

    $(document).on('click', ".main-content", function (event) {
        $('body').removeClass('sidenav-toggled-open');
    });
}


function doubleLayoutFn() {
    doublemenu();
    ActiveSubmenu();
    if (document.querySelector('.slide-menu') && innerWidth >= 992) {
        let sidemenuActive = document.querySelector('.side-menu__item.is-expanded');
        if (sidemenuActive?.nextElementSibling) {
            document.body.classList.remove('sidenav-toggled');
        }

        let doubleActive = document.querySelectorAll('.double-menu-active');
        if (doubleActive.length) {
            doubleActive.forEach(e => e.classList.remove('double-menu-active'))
        }
    }
}

// ______________DOUBLE-MENU JS start
function doublemenu() {
    if (innerWidth >= 992) {
        $(".app-sidebar").off("mouseenter mouseleave");
        document.body.classList.remove('sidenav-toggled')
    }
}
// ______________DOUBLE-MENU JS end

//________________Horizontal js
jQuery(function () {
    'use strict';
    document.addEventListener("touchstart", function () { }, false);
    jQuery(function () {
        jQuery('body').wrapInner('<div class="horizontalMenucontainer" />');
    });
}());

// To remove expanded menu on click 'body'
$(document).on('click', '.horizontal-content', function () {
    $(".app-sidebar li a").each(function () {
        $(this).next().slideUp(300, function () {
            $(this).next().removeClass('open');
        });
        $(this).parent("li").removeClass("is-expanded");
    })
})


// page load active menu 
setTimeout(() => {
    if ($('.slide-item').hasClass('active')) {
        $('.app-sidebar').animate({
            scrollTop: $('a.slide-item.active').offset().top - 600
        }, 600);

    }
    if ($('.sub-side-menu__item').hasClass('active')) {
        $('.app-sidebar').animate({
            scrollTop: $('a.sub-side-menu__item.active').offset().top - 600
        }, 600);
    }

}, 200);

let slideLeft = document.querySelector(".slide-left");
let slideRight = document.querySelector(".slide-right");
slideLeft.addEventListener("click", e => slideClick(), true)
slideRight.addEventListener("click", e => slideClick(), true)

// used to remove is-expanded class and remove class on clicking arrow buttons
function slideClick() {
    let slide = document.querySelectorAll(".slide");
    let slideMenu = document.querySelectorAll(".slide-menu");
    slide.forEach((element, index) => {
        if (element.classList.contains("is-expanded") == true) {
            element.classList.remove("is-expanded")
        }
    });
    slideMenu.forEach((element, index) => {
        if (element.classList.contains("open") == true) {
            element.classList.remove("open");
            element.style.display = "none";
        }
    });
}

// horizontal arrows
var sideMenu = $(".side-menu");
var slide = "100px";

let menuWidth = document.querySelector('.horizontal-main')
let menuItems = document.querySelector('.side-menu')
var prevWidth = []
$(window).resize(
    () => {
        let menuWidth = document.querySelector('.horizontal-main');
        let menuItems = document.querySelector('.side-menu');
        let mainSidemenuWidth = document.querySelector('.main-sidemenu');
        let menuContainerWidth = menuWidth?.offsetWidth - mainSidemenuWidth?.offsetWidth;
        let marginLeftValue = Math.ceil(window.getComputedStyle(menuItems).marginLeft.split('px')[0]);
        let marginRightValue = Math.ceil(window.getComputedStyle(menuItems).marginRight.split('px')[0]);
        let check = menuItems.scrollWidth + (0 - menuWidth?.offsetWidth) + menuContainerWidth;
        // to check and adjst the menu on screen size change
        if ($('body').hasClass('ltr')) {
            if (marginLeftValue >= -check == false && (menuWidth?.offsetWidth - menuContainerWidth) < menuItems.scrollWidth) {
                sideMenu.stop(false, true).animate({
                    marginLeft: -check
                }, {
                    duration: 400
                })
            }
            else {
                sideMenu.stop(false, true).animate({
                    marginLeft: 0
                }, {
                    duration: 400
                })
            }
        }
        else {
            if (marginRightValue > -check == false && menuWidth?.offsetWidth < menuItems.scrollWidth) {
                sideMenu.stop(false, true).animate({
                    marginRight: -check
                }, {
                    duration: 400
                })
            }
            else {
                sideMenu.stop(false, true).animate({
                    marginRight: 0
                }, {
                    duration: 400
                })
            }
        }
        checkHoriMenu();
        responsive();
        HorizontalHovermenu();


        prevWidth.push(window.innerWidth)
        if (prevWidth.length > 3) {
            prevWidth.shift()
        }
        let prevValue = prevWidth[prevWidth.length - 2];
        if (window.innerWidth >= 992 && prevValue < 992) {
            if (document.querySelector('body').classList.contains('horizontal')) {
                let li = document.querySelectorAll('.side-menu li')
                li.forEach((e, i) => {
                    e.classList.remove('is-expanded')
                })
                var animationSpeed = 300;
                // first level
                var parent = $("[data-bs-toggle='sub-slide']").parents('ul');
                var ul = parent.find('ul.slide-menu:visible').slideUp(animationSpeed);
                ul.removeClass('open');
                var parent1 = $("[data-bs-toggle='sub-slide2']").parents('ul');
                var ul1 = parent1.find('ul.sub-slide-menu:visible').slideUp(animationSpeed);
                ul1.removeClass('open');
                document.body.classList.remove('sidenav-toggled');
            }
        }
        else {
            ActiveSubmenu();
        }
    }
)

function ActiveSubmenu() {

    var position = window.location.pathname.split('/');
    position = position[position.length - 1];
    $(".app-sidebar li a").each(function () {
        var $this = $(this);
        var pageUrl = $this.attr("href");
        let prevValue = [window.innerWidth];
        if (prevValue.length > 1) {
            prevValue = prevWidth[prevWidth.length - 2];
        }


        if (pageUrl === position) {
            setTimeout(() => {
                if ($this.closest('.sub-slide-menu2')) {
                    $this.closest('.sub-slide-menu2').addClass('open');
                    if (!document.querySelector('body').classList.contains('horizontal') || window.innerWidth < 992) {
                        $this.closest('.sub-slide-menu2').slideDown();
                    }
                    $this.closest('.sub-slide-menu2').prev().addClass('active');
                    $this.closest('.sub-slide-menu2').parent().addClass('is-expanded');
                }
                if ($this.closest('.sub-slide-menu')) {
                    $this.closest('.sub-slide-menu').addClass('open');
                    if (!document.querySelector('body').classList.contains('horizontal') || window.innerWidth < 992) {
                        $this.closest('.sub-slide-menu').slideDown();
                    }
                    $this.closest('.sub-slide-menu').parent().addClass('is-expanded');
                    $this.closest('.sub-slide-menu').prev().addClass('active');

                }
                if ($this.closest('.slide-menu')) {
                    $this.closest('.slide-menu').addClass('open');
                    if (!document.querySelector('body').classList.contains('horizontal') || window.innerWidth < 992) {
                        $this.closest('.slide-menu').slideDown();
                    }
                    $this.closest('.slide-menu').parent().addClass('is-expanded');
                    $this.closest('.slide-menu').prev().addClass('active');

                }
                $this.addClass('active');
                $this.parent().addClass('active');

                if (document.body.classList.contains('double-menu-tabs') || document.body.classList.contains('double-menu')) {
                    if ($this.closest('.slide-menu').length) {
                        $this.closest('.slide-menu').addClass('double-menu-active');
                    }
                    else {
                        let slideMenu = document.querySelectorAll('.slide-menu'),
                        slideNavStatus = false;
                        slideMenu.forEach(e => {
                            if(e.classList.contains('double-menu-active')){
                                slideNavStatus = true;
                            }
                        })
                        if(!slideNavStatus){
                            document.body.classList.add('sidenav-toggled');
                        }
                    }
                }
            }, 200);
        }

    });
}


function checkHoriMenu() {

    let menuWidth = document.querySelector('.horizontal-main')
    let menuItems = document.querySelector('.side-menu')
    let mainSidemenuWidth = document.querySelector('.main-sidemenu')
    let menuContainerWidth = menuWidth?.offsetWidth - mainSidemenuWidth?.offsetWidth
    let marginLeftValue = Math.ceil(window.getComputedStyle(menuItems).marginLeft.split('px')[0]);
    let marginRightValue = Math.ceil(window.getComputedStyle(menuItems).marginRight.split('px')[0]);
    let check = menuItems.scrollWidth + (0 - menuWidth?.offsetWidth) + menuContainerWidth;

    if ($('body').hasClass('ltr')) {
        menuItems.style.marginRight = 0
    }
    else {
        menuItems.style.marginLeft = 0;
    }

    setTimeout(() => {
        if (menuItems.scrollWidth - 2 < (menuWidth?.offsetWidth - menuContainerWidth)) {
            $("#slide-right").addClass("d-none");
            $("#slide-left").addClass("d-none");
        }
        else if (marginLeftValue != 0) {
            $("#slide-left").removeClass("d-none");
        }
        else if (marginLeftValue != -check) {
            $("#slide-right").removeClass("d-none");
        }
        else if (marginRightValue != 0) {
            $("#slide-left").removeClass("d-none");
        }
        else if (marginRightValue != -check) {
            $("#slide-right").removeClass("d-none");
        }

        if (marginLeftValue == 0 || marginRightValue == 0) {
            $("#slide-left").addClass("d-none");
            $("#slide-right").removeClass("d-none");
        }
    }, 200)

}

checkHoriMenu();

$(document).on("click", ".ltr #slide-left", function () {
    let menuWidth = document.querySelector('.horizontal-main')
    let menuItems = document.querySelector('.side-menu')
    let mainSidemenuWidth = document.querySelector('.main-sidemenu')
    let menuContainerWidth = menuWidth?.offsetWidth - mainSidemenuWidth?.offsetWidth
    let marginLeftValue = Math.ceil(window.getComputedStyle(menuItems).marginLeft.split('px')[0]) + 100;

    if (marginLeftValue < 0) {
        sideMenu.stop(false, true).animate({
            marginLeft: "+=" + slide
        }, {
            duration: 400
        })
        if ((menuWidth?.offsetWidth - menuContainerWidth) < menuItems.scrollWidth) {
            $("#slide-right").removeClass("d-none");
        }
    }
    else {
        $("#slide-left").addClass("d-none");
        $("#slide-right").removeClass("d-none");
    }

    if (marginLeftValue >= 0) {
        sideMenu.stop(false, true).animate({
            marginLeft: 0
        }, {
            duration: 400
        })

        if (menuWidth?.offsetWidth < menuItems.scrollWidth) {
            // $("#slide-left").addClass("d-none");
        }
    }
    // to remove dropdown when clicking arrows in horizontal menu
    let subNavSub = document.querySelectorAll('.sub-nav-sub');
    subNavSub.forEach((e) => {
        e.style.display = '';
    })
    let subNav = document.querySelectorAll('.nav-sub')
    subNav.forEach((e) => {
        e.style.display = '';
    })
    //
});
$(document).on("click", ".ltr #slide-right", function () {
    let menuWidth = document.querySelector('.app-sidebar')
    let menuItems = document.querySelector('.side-menu')
    let mainSidemenuWidth = document.querySelector('.main-sidemenu')
    let menuContainerWidth = menuWidth?.offsetWidth - mainSidemenuWidth?.offsetWidth
    let marginLeftValue = Math.ceil(window.getComputedStyle(menuItems).marginLeft.split('px')[0]) - 100;
    let check = menuItems.scrollWidth + (0 - menuWidth?.offsetWidth) + menuContainerWidth;
    if (marginLeftValue > -check) {
        sideMenu.stop(false, true).animate({
            // marginLeft : 0,
            marginLeft: "-=" + slide,
            marginRight: 0,
        }, {
            duration: 400
        })
    }
    else {
        sideMenu.stop(false, true).animate({
            // marginLeft : 0,
            marginRight: 0,
            marginLeft: -check
        }, {
            duration: 400
        });

        $("#slide-right").addClass("d-none");
    }
    if (marginLeftValue != 0) {
        $("#slide-left").removeClass("d-none");
    }
    // to remove dropdown when clicking arrows in horizontal menu
    let subNavSub = document.querySelectorAll('.sub-nav-sub');
    subNavSub.forEach((e) => {
        e.style.display = '';
    })
    let subNav = document.querySelectorAll('.nav-sub')
    subNav.forEach((e) => {
        e.style.display = '';
    })
    //
});

$(document).on("click", ".rtl #slide-left", function () {
    let menuWidth = document.querySelector('.horizontal-main')
    let menuItems = document.querySelector('.side-menu')
    let mainSidemenuWidth = document.querySelector('.main-sidemenu')
    let menuContainerWidth = menuWidth?.offsetWidth - mainSidemenuWidth?.offsetWidth
    let marginRightValue = Math.ceil(window.getComputedStyle(menuItems).marginRight.split('px')[0]) + 100;

    if (marginRightValue < 0) {
        sideMenu.stop(false, true).animate({
            // marginRight : 0,
            marginLeft: 0,
            marginRight: "+=" + slide
        }, {
            duration: 400
        })
        if ((menuWidth?.offsetWidth - menuContainerWidth) < menuItems.scrollWidth) {
            $("#slide-right").removeClass("d-none");
        }
    }
    else {
        $("#slide-left").addClass("d-none");
    }

    if (marginRightValue >= 0) {
        $("#slide-left").addClass("d-none");
        sideMenu.stop(false, true).animate({
            // marginRight : 0,
            marginLeft: 0
        }, {
            duration: 400
        })
    }
    // to remove dropdown when clicking arrows in horizontal menu
    let subNavSub = document.querySelectorAll('.sub-nav-sub');
    subNavSub.forEach((e) => {
        e.style.display = '';
    })
    let subNav = document.querySelectorAll('.nav-sub')
    subNav.forEach((e) => {
        e.style.display = '';
    })
    //
});
$(document).on("click", ".rtl #slide-right", function () {
    let menuWidth = document.querySelector('.app-sidebar')
    let menuItems = document.querySelector('.side-menu')
    let mainSidemenuWidth = document.querySelector('.main-sidemenu')
    let menuContainerWidth = menuWidth?.offsetWidth - mainSidemenuWidth?.offsetWidth
    let marginRightValue = Math.ceil(window.getComputedStyle(menuItems).marginRight.split('px')[0]) - 100;
    let check = menuItems.scrollWidth + (0 - menuWidth?.offsetWidth) + menuContainerWidth;
    if (marginRightValue > -check) {
        sideMenu.stop(false, true).animate({
            // marginLeft : 0,
            marginLeft: 0,
            marginRight: "-=" + slide
        }, {
            duration: 400
        })

    }
    else {
        sideMenu.stop(false, true).animate({
            // marginLeft : 0,
            marginLeft: 0,
            marginRight: -check
        }, {
            duration: 400
        })
        $("#slide-right").addClass("d-none");
    }

    if (marginRightValue != 0) {
        $("#slide-left").removeClass("d-none");
    }
    // to remove dropdown when clicking arrows in horizontal menu
    let subNavSub = document.querySelectorAll('.sub-nav-sub');
    subNavSub.forEach((e) => {
        e.style.display = '';
    })
    let subNav = document.querySelectorAll('.nav-sub')
    subNav.forEach((e) => {
        e.style.display = '';
    })
});

function menuClick() {
    $("[data-bs-toggle='slide']").off('click');
    $("[data-bs-toggle='sub-slide']").off('click')
    $("[data-bs-toggle='sub-slide2']").off('click')
    $("[data-bs-toggle='slide']").on('click', function (e) {

        var $this = $(this);
        var checkElement = $this.next();
        var animationSpeed = 300,
            slideMenuSelector = '.slide-menu';
        if (checkElement.is(slideMenuSelector) && checkElement.is(':visible')) {
            checkElement.slideUp(animationSpeed, function () {
                checkElement.removeClass('open');
            });
            checkElement.parent("li").removeClass("is-expanded");
        }
        else if ((checkElement.is(slideMenuSelector)) && (!checkElement.is(':visible'))) {
            var parent = $this.parents('ul').first();
            var ul = parent.find('ul[class^="slide-menu"]:visible').slideUp(animationSpeed);
            ul.removeClass('open');
            var parent_li = $this.parent("li");
            checkElement.slideDown(animationSpeed, function () {
                checkElement.addClass('open');
                parent.find('li.is-expanded').removeClass('is-expanded');
                parent_li.addClass('is-expanded');
            });
        }
        if (checkElement.is(slideMenuSelector)) {
            e.preventDefault();
        }


        if (window.innerWidth >= 992) {
            if (!checkElement.hasClass('double-menu-active') && !document.body.classList.contains('horizontal') && (document.body.classList.contains('double-menu') || document.body.classList.contains('double-menu-tabs'))) {

                if (document.querySelector('.slide-menu')) {
                    let slidemenu = document.querySelectorAll('.slide-menu');
                    slidemenu.forEach(e => {
                        if (e.classList.contains('double-menu-active')) {
                            e.classList.remove('double-menu-active')
                        }
                    })
                }

                checkElement.addClass('double-menu-active');
                document.body.classList.remove("sidenav-toggled")
            }
        }
    });
    // Activate sidebar slide toggle	
    $("[data-bs-toggle='sub-slide']").on('click', function (e) {
        var $this = $(this);
        var checkElement = $this.next();
        var animationSpeed = 300,
            slideMenuSelector = '.sub-slide-menu';
        if (checkElement.is(slideMenuSelector) && checkElement.is(':visible')) {
            checkElement.slideUp(animationSpeed, function () {
                checkElement.removeClass('open');
            });
            checkElement.parent("li").removeClass("is-expanded");
        }
        else if ((checkElement.is(slideMenuSelector)) && (!checkElement.is(':visible'))) {
            var parent = $this.parents('ul').first();
            var ul = parent.find('ul[class^="sub-slide-menu"]:visible').slideUp(animationSpeed);
            ul.removeClass('open');
            var parent_li = $this.parent("li");
            checkElement.slideDown(animationSpeed, function () {
                checkElement.addClass('open');
                parent.find('li.is-expanded').removeClass('is-expanded');
                parent_li.addClass('is-expanded');
            });
        }
        if (checkElement.is(slideMenuSelector)) {
            e.preventDefault();
        }
    });
    // Activate sidebar slide toggle	
    $("[data-bs-toggle='sub-slide2']").on('click', function (e) {
        var $this = $(this);
        var checkElement = $this.next();
        var animationSpeed = 300,
            slideMenuSelector = '.sub-slide-menu2';
        if (checkElement.is(slideMenuSelector) && checkElement.is(':visible')) {
            checkElement.slideUp(animationSpeed, function () {
                checkElement.removeClass('open');
            });
            checkElement.parent("li").removeClass("is-expanded");
        }
        else if ((checkElement.is(slideMenuSelector)) && (!checkElement.is(':visible'))) {
            var parent = $this.parents('ul').first();
            var ul = parent.find('ul[class^="sub-slide-menu"]:visible').slideUp(animationSpeed);
            ul.removeClass('open');
            var parent_li = $this.parent("li");
            checkElement.slideDown(animationSpeed, function () {
                checkElement.addClass('open');
                parent.find('li.is-expanded').removeClass('is-expanded');
                parent_li.addClass('is-expanded');
            });
        }
        if (checkElement.is(slideMenuSelector)) {
            e.preventDefault();
        }
    });
}

function HorizontalHovermenu() {
    let value = document.querySelector('body').classList.contains('horizontal-hover')
    if (value && window.innerWidth >= 992) {
        $("[data-bs-toggle='slide']").off('click');
        $("[data-bs-toggle='sub-slide']").off('click')
        $("[data-bs-toggle='sub-slide2']").off('click')
        slideClick()
    }
    else {
        menuClick();
    }
} 
document.querySelector('.main-content').addEventListener('click', () => {
    if (document.querySelector('body').classList.contains('horizontal')) {
        let li = document.querySelectorAll('.side-menu li')
        li.forEach((e, i) => {
            e.classList.remove('is-expanded')
        })
        var animationSpeed = 300;
        // first level
        var parent = $("[data-bs-toggle='sub-slide']").parents('ul');
        var ul = parent.find('ul:visible').slideUp(animationSpeed);
        ul.removeClass('open');
        var parent1 = $("[data-bs-toggle='sub-slide2']").parents('ul');
        var ul1 = parent1.find('ul:visible').slideUp(animationSpeed);
        ul1.removeClass('open');
    }
}, true)
/* ========================================================================
 * Bootstrap: sidebar.js v0.1
 * ========================================================================
 * Copyright 2011-2014 Asyraf Abdul Rahman
 * Licensed under MIT
 * ======================================================================== */

+ function($) {
    'use strict';

    // SIDEBAR PUBLIC CLASS DEFINITION
    // ================================

    var Sidebar = function(element, options) {
        this.$element = $(element)
        this.options = $.extend({}, Sidebar.DEFAULTS, options)
        this.transitioning = null

        if (this.options.parent) this.$parent = $(this.options.parent)
        if (this.options.toggle) this.toggle()
    }

    Sidebar.DEFAULTS = {
        toggle: true
    }

    Sidebar.prototype.show = function() {
        if (this.transitioning || this.$element.hasClass('sidebar-open')) return


        var startEvent = $.Event('show.bs.sidebar')
        this.$element.trigger(startEvent);
        if (startEvent.isDefaultPrevented()) return

        this.$element
            .addClass('sidebar-open')

        this.transitioning = 1

        var complete = function() {
            this.$element
            this.transitioning = 0
            this.$element.trigger('shown.bs.sidebar')
        }

        if (!$.support.transition) return complete.call(this)

        this.$element
            .one($.support.transition.end, $.proxy(complete, this))
            .emulateTransitionEnd(400)
    }

    Sidebar.prototype.hide = function() {
        if (this.transitioning || !this.$element.hasClass('sidebar-open')) return

        var startEvent = $.Event('hide.bs.sidebar')
        this.$element.trigger(startEvent)
        if (startEvent.isDefaultPrevented()) return

        this.$element
            .removeClass('sidebar-open')

        this.transitioning = 1

        var complete = function() {
            this.transitioning = 0
            this.$element
                .trigger('hidden.bs.sidebar')
        }

        if (!$.support.transition) return complete.call(this)

        this.$element
            .one($.support.transition.end, $.proxy(complete, this))
            .emulateTransitionEnd(400)
    }

    Sidebar.prototype.toggle = function() {
        this[this.$element.hasClass('sidebar-open') ? 'hide' : 'show']()
    }

    var old = $.fn.sidebar

    $.fn.sidebar = function(option) {
        return this.each(function() {
            var $this = $(this)
            var data = $this.data('bs.sidebar')
            var options = $.extend({}, Sidebar.DEFAULTS, $this.data(), typeof options == 'object' && option)

            if (!data && options.toggle && option == 'show') option = !option
            if (!data) $this.data('bs.sidebar', (data = new Sidebar(this, options)))
            if (typeof option == 'string') data[option]()
        })
    }

    $.fn.sidebar.Constructor = Sidebar

    $.fn.sidebar.noConflict = function() {
        $.fn.sidebar = old
        return this
    }

    $(document).on('click.bs.sidebar.data-api', '[data-bs-toggle="sidebar-right"]', function(e) {
        var $this = $(this),
            href
        var target = $this.attr('data-target') ||
            e.preventDefault() ||
            (href = $this.attr('href')) && href.replace(/.*(?=#[^\s]+$)/, '')
        var $target = $(target)
        var data = $target.data('bs.sidebar')
        var option = data ? 'toggle' : $this.data()

        $target.sidebar(option)
    })

    $('html').on('click.bs.sidebar.autohide', function(event) {
        var $this = $(event.target);
        var isButtonOrSidebar = $this.is('.sidebar, [data-bs-toggle="sidebar-right"]') || $this.parents('.sidebar, [data-bs-toggle="sidebar-right"]').length;
        if (isButtonOrSidebar) {
            return;
        } else {
            var $target = $('.sidebar');
            $target.each(function(i, trgt) {
                var $trgt = $(trgt);
                if ($trgt.data('bs.sidebar') && $trgt.hasClass('sidebar-open')) {
                    $trgt.sidebar('hide');
                }
            })
        }
    });
}(jQuery);
(function() {
    'use strict'

    let stickyElement = $(".sticky"),
     stickyClass = "sticky-pin",
     stickyPos = 66, //Distance from the top of the window.
     stickyHeight;


    ///Create a negative margin to prevent content 'jumps':
    stickyElement.after('<div class="jumps-prevent"></div>');

    function jumpsPrevent() {
        stickyHeight = stickyElement.innerHeight();
        stickyElement.css({ "margin-bottom": "-" + stickyHeight + "px" });
        stickyElement.next().css({ "padding-top": +stickyHeight + "px" });
    };
    jumpsPrevent(); //Run.

    //Function trigger:
    $(window).on('resize', function() {
        jumpsPrevent();

    });

    //Sticker function:
    function stickerFn() {
        let winTop = $(window).scrollTop();
        //Check element position:
        winTop >= stickyPos ?
            stickyElement.addClass('stickyClass') :
            stickyElement.removeClass('stickyClass') //Boolean class switcher.
    };
    stickerFn(); //Run.
    $(window).on('scroll',function() {
        stickerFn();
    });
    
    $('.app-sidebar').on('scroll', function() {
        let s = $(".app-sidebar .ps__rail-y");
        if (s[0].style.top.split('px')[0] <= 60 ) {
            $('.app-sidebar').removeClass('sidemenu-scroll')
        } else {
            $('.app-sidebar').addClass('sidemenu-scroll')
        }

    })

})();

(function ($) {
    "use strict";

    // PAGE LOADING
    $(window).on("load", function (e) {
        $("#global-loader").fadeOut("slow");
    })

    // FULL SCREEN
    $(document).on("click", ".fullscreen-button", function toggleFullScreen() {
        $('.fullscreen-button').addClass('fullscreen-button');
        if ((document.fullScreenElement !== undefined && document.fullScreenElement === null) || (document.msFullscreenElement !== undefined && document.msFullscreenElement === null) || (document.mozFullScreen !== undefined && !document.mozFullScreen) || (document.webkitIsFullScreen !== undefined && !document.webkitIsFullScreen)) {
            if (document.documentElement.requestFullScreen) {
                document.documentElement.requestFullScreen();
            } else if (document.documentElement.mozRequestFullScreen) {
                document.documentElement.mozRequestFullScreen();
            } else if (document.documentElement.webkitRequestFullScreen) {
                document.documentElement.webkitRequestFullScreen(Element.ALLOW_KEYBOARD_INPUT);
            } else if (document.documentElement.msRequestFullscreen) {
                document.documentElement.msRequestFullscreen();
            }
        } else {
            $('html').removeClass('fullscreen-button');
            if (document.cancelFullScreen) {
                document.cancelFullScreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.webkitCancelFullScreen) {
                document.webkitCancelFullScreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        }
    })

    // BACK TO TOP BUTTON
    $(window).on("scroll", function (e) {
        if ($(this).scrollTop() > 0) {
            $('#back-to-top').fadeIn('slow');
        } else {
            $('#back-to-top').fadeOut('slow');
        }
    });
    $(document).on("click", "#back-to-top", function (e) {
        $("html, body").animate({
            scrollTop: 0
        }, 0);
        return false;
    });



    // QUANTITY CART INCREASE AND DECREASE
    $('.add').on('click', function () {
        var $qty = $(this).closest('div').find('.qty');
        var currentVal = parseInt($qty.val());
        if (!isNaN(currentVal)) {
            $qty.val(currentVal + 1);
        }
    });
    $('.minus').on('click', function () {
        var $qty = $(this).closest('div').find('.qty');
        var currentVal = parseInt($qty.val());
        if (!isNaN(currentVal) && currentVal > 0) {
            $qty.val(currentVal - 1);
        }
    });

    // CHART CIRCLE
    if ($('.chart-circle').length) {
        $('.chart-circle').each(function () {
            let $this = $(this);
            $this.circleProgress({
                fill: {
                    color: $this.attr('data-bs-color')
                },
                size: $this.height(),
                startAngle: -Math.PI / 4 * 2,
                emptyFill: '#edf0f5',
                lineCap: 'round'
            });
        });
    }

    // MODAL
    // SHOWING MODAL WITH EFFECT
    $('.modal-effect').on('click', function (e) {
        e.preventDefault();
        var effect = $(this).attr('data-bs-effect');
        $('#modaldemo8').addClass(effect);
    });

    // HIDE MODAL WITH EFFECT
    $('#modaldemo8').on('hidden.bs.modal', function (e) {
        $(this).removeClass(function (index, className) {
            return (className.match(/(^|\s)effect-\S+/g) || []).join(' ');
        });
    });

    // CARD
    const DIV_CARD = 'div.card';

    // TOOLTIP
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })

    // POPOVER
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl)
    })

    // BY DEFAULT, BOOTSTRAP DOESN'T AUTO CLOSE POPOVER AFTER APPEARING IN THE PAGE 
    $(document).on('click', function (e) {
        $('[data-bs-toggle="popover"],[data-original-title]').each(function () {
            //the 'is' for buttons that trigger popups
            //the 'has' for icons within a button that triggers a popup
            if (!$(this).is(e.target) && $(this).has(e.target).length === 0 && $('.popover').has(e.target).length === 0) {
                (($(this).popover('hide').data('bs.popover') || {}).inState || {}).click = false // fix for BS 3.3.6
            }

        });
    });

    // TOAST
    var toastElList = [].slice.call(document.querySelectorAll('.toast'))
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl)
    })
    $(document).on("click", '#liveToastBtn', function () {
        $('.toast').toast('show');
    })

    //  FUNCTION FOR REMOVE CARD
    $(document).on('click', '[data-bs-toggle="card-remove"]', function (e) {
        let $card = $(this).closest(DIV_CARD);
        $card.remove();
        e.preventDefault();
        return false;
    });


    // FUNCTIONS FOR COLLAPSED CARD
    $(document).on('click', '[data-bs-toggle="card-collapse"]', function (e) {
        let $card = $(this).closest(DIV_CARD);
        $card.toggleClass('card-collapsed');
        e.preventDefault();
        return false;
    });

    // CARD FULL SCREEN
    $(document).on('click', '[data-bs-toggle="card-fullscreen"]', function (e) {
        let $card = $(this).closest(DIV_CARD);
        $card.toggleClass('card-fullscreen').removeClass('card-collapsed');
        e.preventDefault();
        return false;
    });


    // INPUT FILE BROWSER
    $(document).on('change', '.file-browserinput', function () {
        var input = $(this),
            numFiles = input.get(0).files ? input.get(0).files.length : 1,
            label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
        input.trigger('fileselect', [numFiles, label]);
    }); // We can watch for our custom `fileselect` event like this

    // FILE UPLOAD
    $('.file-browserinput').on('fileselect', function (event, numFiles, label) {
        var input = $(this).parents('.input-group').find(':text'),
            log = numFiles > 1 ? numFiles + ' files selected' : label;
        if (input.length) {
            input.val(log);
        } else {
            if (log) alert(log);
        }
    });



    // ______________ SWITCHER-toggle ______________//

    $('.layout-setting').on("click", function (e) {
        if (!(document.querySelector('body').classList.contains('dark-mode'))) {
            $('body').addClass('dark-mode');
            $('body').removeClass('light-mode');
            $('body').removeClass('transparent-mode');

            $('body')?.removeClass('color-menu');
            $('body')?.removeClass('gradient-menu');
            $('body')?.removeClass('light-menu');
            $('body')?.removeClass('color-header');
            $('body')?.removeClass('gradient-header');
            $('body')?.removeClass('header-light');

            $('#myonoffswitch5').prop('checked', true);
            $('#myonoffswitch8').prop('checked', true);

            localStorage.setItem('sashdarkMode', true);
            localStorage.removeItem('sashlightMode');
            localStorage.removeItem('sashtransparentMode');
            $('#myonoffswitch2').prop('checked', true);

        } else {
            $('body').removeClass('dark-mode');
            $('body').addClass('light-mode');
            $('#myonoffswitch3').prop('checked', true);
            $('#myonoffswitch6').prop('checked', true);

            localStorage.setItem('sashlightMode', true);
            localStorage.removeItem('sashtransparentMode');
            localStorage.removeItem('sashdarkMode');
            $('#myonoffswitch1').prop('checked', true);
        }
    });

    // ACCORDION STYLE
    $(document).on("click", '[data-bs-toggle="collapse"]', function () {
        $(this).toggleClass('active').siblings().removeClass('active');
    });

    // EMAIL INBOX
    $(".clickable-row").on('click', function () {
        window.location = $(this).data("href");
    });


})(jQuery);

// REPLY
function replay() {
    "use strict";

    let replayButtom = document.querySelectorAll('.reply a')
    // Creating Div
    let Div = document.createElement('div')
    Div.setAttribute('class', "comment mt-5 d-grid")
    // creating textarea
    let textArea = document.createElement('textarea')
    textArea.setAttribute('class', "form-control")
    textArea.setAttribute('rows', "5")
    textArea.innerText = "Your Comment";
    // creating Cancel buttons
    let cancelButton = document.createElement('button');
    cancelButton.setAttribute('class', "btn btn-danger");
    cancelButton.innerText = "Cancel";

    let buttonDiv = document.createElement('div')
    buttonDiv.setAttribute('class', "btn-list ms-auto mt-2")

    // Creating submit button
    let submitButton = document.createElement('button');
    submitButton.setAttribute('class', "btn btn-success ms-3");
    submitButton.innerText = "Submit";

    // appending text are to div
    Div.append(textArea)
    Div.append(buttonDiv);
    buttonDiv.append(cancelButton);
    buttonDiv.append(submitButton);

    replayButtom.forEach((element, index) => {

        element.addEventListener('click', () => {
            let replay = $(element).parent()
            replay.append(Div)

            cancelButton.addEventListener('click', () => {
                Div.remove()
            })
        })
    })


}
replay()

// OFF-CANVAS STYLE
$('.off-canvas').on('click', function () {
    $('body').addClass('overflow-y-scroll');
    $('body').addClass('pe-0');
});
// FOOTER
if (!document.querySelector('.login-img')) {
    document.getElementById("year").innerHTML = new Date().getFullYear();
}








