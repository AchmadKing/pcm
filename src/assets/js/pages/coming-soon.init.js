/*
Template Name: Stexo -  Admin & Dashboard Template
Author: Themesdesign
Contact: themesdesign.in@gmail.com
File: coming soon Init Js File
*/

// $('[data-countdown]').each(function () {
//     var $this = $(this), finalDate = $(this).data('countdown');
//     $this.countdown(finalDate, function (event) {
//         $(this).html(event.strftime(''
//         + '<div class="coming-box">%D <span>Days</span></div> '
//         + '<div class="coming-box">%H <span>Hours</span></div> '
//         + '<div class="coming-box">%M <span>Minutes</span></div> '
//         + '<div class="coming-box">%S <span>Seconds</span></div> '));
//     });
// });

$(document).ready(function () {

    // Countdown
    // To change date, simply edit: var endDate = "January 17, 2019 20:39:00";
    $(function () {
        var endDate = "January 17, 2025 20:39:00";

        $('.comming-watch .countdown').countdown({
            date: endDate,
            render: function (data) {
                $(this.el).html('<div><div class="card card-body p-3"><span class="countdown-num">' + (parseInt(this.leadingZeros(data.years, 2) * 365) + parseInt(this.leadingZeros(data.days, 2))) + '</span><span class="text-uppercase">days</span></div><div class="card card-body p-3"><span class="countdown-num">' + this.leadingZeros(data.hours, 2) + '</span><span class="text-uppercase">hours</span></div></div><div class="lj-countdown-ms "><div class="card card-body p-3"><span class="countdown-num">' + this.leadingZeros(data.min, 2) + '</span><span class="text-uppercase">minutes</span></div><div class="card card-body p-3"><span class="countdown-num">' + this.leadingZeros(data.sec, 2) + '</span><span class="text-uppercase">seconds</span></div></div>');
            }
        });

    });
});
