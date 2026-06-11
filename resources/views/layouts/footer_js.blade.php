<script src="{{ asset('/assets/js/Chart.min.js') }}"></script>
<script src="{{ asset('/assets/js/jquery.validate.min.js') }}"></script>
<script src="{{ asset('/assets/jquery-toast-plugin/jquery.toast.min.js') }}"></script>
<script src="{{ asset('/assets/select2/select2.min.js') }}"></script>

<script src="{{ asset('/assets/js/off-canvas.js') }}"></script>
<script src="{{ asset('/assets/js/hoverable-collapse.js') }}"></script>
<script src="{{ asset('/assets/js/misc.js') }}"></script>
<script src="{{ asset('/assets/js/settings.js') }}"></script>
<script src="{{ asset('/assets/js/todolist.js') }}"></script>
<script src="{{ asset('/assets/js/ekko-lightbox.min.js') }}"></script>
<script src="{{ asset('/assets/js/jquery.tagsinput.min.js') }}"></script>

<script src="{{ asset('/assets/js/apexcharts.js') }}"></script>




{{--<script src="{{ asset('/assets/bootstrap-table/bootstrap-table.min.js') }}"></script>--}}

<script src="https://unpkg.com/bootstrap-table@1.22.1/dist/bootstrap-table.min.js"></script>
<script src="{{ asset('/assets/bootstrap-table/bootstrap-table-mobile.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/bootstrap-table-export.min.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/fixed-columns.min.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/tableExport.min.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/jspdf.min.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/jspdf.plugin.autotable.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/reorder-rows.min.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/jquery.tablednd.min.js') }}"></script>
<script src="{{ asset('/assets/bootstrap-table/loadash.min.js') }}"></script>

<script src="{{ asset('/assets/js/jquery.cookie.js') }}"></script>
<script src="{{ asset('/assets/js/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('/assets/js/momentjs.js') }}"></script>
<script src="{{ asset('/assets/js/datepicker.min.js') }}"></script>
<script src="{{ asset('/assets/js/daterangepicker.js') }}"></script>
<script src="{{ asset('/assets/js/jquery.repeater.js') }}"></script>
<script src="{{ asset('/assets/tinymce/tinymce.min.js') }}"></script>

<script src="{{ asset('/assets/color-picker/jquery-asColor.min.js') }}"></script>
<script src="{{ asset('/assets/color-picker/color.min.js') }}"></script>

<script src="{{ asset('/assets/js/custom/validate.js') }}"></script>
<script src="{{ asset('/assets/js/jquery-additional-methods.min.js')}}"></script>
<script src="{{ asset('/assets/js/custom/function.js') }}"></script>
<script src="{{ asset('/assets/js/custom/common.js') }}"></script>
<script src="{{ asset('/assets/js/custom/custom.js') }}"></script>
<script src="{{ asset('/assets/js/custom/bootstrap-table/actionEvents.js') }}"></script>
<script src="{{ asset('/assets/js/custom/bootstrap-table/formatter.js') }}"></script>
<script src="{{ asset('/assets/js/custom/bootstrap-table/queryParams.js') }}"></script>

<script src="{{ asset('/assets/ckeditor-4/ckeditor.js') }}"></script>
<script src="{{ asset('/assets/ckeditor-4/adapters/jquery.js') }}" async></script>
<script src="{{ asset('/assets/js/dragula.min.js') }}"></script>


<script type="application/json" id="toast-data-json">
{
    "errors": {!! json_encode($errors->all()) !!},
    "success": "{{ Session::get('success') }}",
    "error": "{{ Session::get('error') }}"
}
</script>

<script type="text/javascript">
    (function() {
        var toastData = JSON.parse(document.getElementById('toast-data-json').textContent);

        if (toastData.errors.length > 0) {
            toastData.errors.forEach(function(error) {
                $.toast({
                    text: error,
                    showHideTransition: 'slide',
                    icon: 'error',
                    loaderBg: '#f2a654',
                    position: 'top-right'
                });
            });
        }

        if (toastData.success) {
            $.toast({
                text: toastData.success,
                showHideTransition: 'slide',
                icon: 'success',
                loaderBg: '#f96868',
                position: 'top-right'
            });
        }

        if (toastData.error) {
            $.toast({
                text: toastData.error,
                showHideTransition: 'slide',
                icon: 'error',
                loaderBg: '#f2a654',
                position: 'top-right'
            });
        }
    })();
</script>

<script type="application/json" id="currency-config-json">
@php
    // 获取显示货币配置
    $displayCurrency = $systemSettings['display_currency'] ?? 'MMK';
    
    // 汇率配置（非法值 fallback 到默认值）
    $usdRate = (float)($systemSettings['usd_exchange_rate'] ?? 3500) ?: 3500;
    $cnyRate = (float)($systemSettings['cny_exchange_rate'] ?? 500) ?: 500;
    
    // 验证显示货币
    $validCurrencies = ['MMK', 'CNY', 'USD'];
    if (!in_array($displayCurrency, $validCurrencies)) {
        $displayCurrency = 'MMK';
    }
    
    // 获取货币符号
    $symbolMap = ['MMK' => 'K', 'CNY' => '¥', 'USD' => '$'];
    $currencySymbol = $symbolMap[$displayCurrency] ?? 'K';
@endphp
{
    "currency": "{{ $displayCurrency }}",
    "symbol": "{{ $currencySymbol }}",
    "position": "{{ $schoolSettings['currency_position'] ?? 'after' }}",
    "rates": {
        "MMK": 1,
        "CNY": {{ $cnyRate }},
        "USD": {{ $usdRate }}
    }
}
</script>

<script type="application/json" id="blade-vars-json">
{
    "please_wait": "{{__('Please wait')}}",
    "processing_your_request": "{{__('Processing your request')}}",
    "date_format_raw": "{{ $schoolSettings['date_format'] ?? $systemSettings['date_format'] ?? 'd-m-Y' }}",
    "time_format_raw": "{{ $schoolSettings['time_format'] ?? $systemSettings['time_format'] ?? 'h:i A' }}",
    "currency_code": "{{ $system_settings['currency_code'] ?? 'MMK' }}",
    "system_name": "{{ $system_settings['system_name'] ?? 'eSchool-Saas' }}",
    "razorpay_key": "{{ $paymentConfiguration->api_key ?? '' }}"
}
</script>

<script>
    (function() {
        var bladeVars = JSON.parse(document.getElementById('blade-vars-json').textContent);
        var currencyConfig = JSON.parse(document.getElementById('currency-config-json').textContent);

        window.please_wait = bladeVars.please_wait;
        window.processing_your_request = bladeVars.processing_your_request;

        var date_format = bladeVars.date_format_raw.replace('Y', 'YYYY').replace('m', 'MM').replace('d', 'DD');
        var date_time_format = (bladeVars.date_format_raw + ' ' + bladeVars.time_format_raw).replace('Y', 'YYYY').replace('m', 'MM').replace('d', 'DD').replace('h', 'hh').replace('H', 'HH').replace('i', 'mm').replace('a', 'a').replace('A', 'A');
        var time_format = bladeVars.time_format_raw.replace('h', 'hh').replace('H', 'HH').replace('i', 'mm').replace('a', 'a').replace('A', 'A');
        
        // 金额格式化全局配置
        window.currencyConfig = currencyConfig;

        /**
         * JS 统一金额格式化函数 - 支持多货币转换
         * @param {number|string} amount MMK原始金额
         * @param {string|null} targetCurrency 目标货币: MMK | CNY | USD，默认取系统设置
         * @param {boolean} withSymbol 是否显示符号
         * @returns {string}
         * 
         * 示例:
         *   formatMoneyJS(100000)        -> 根据系统设置显示
         *   formatMoneyJS(100000, 'MMK') -> "100,000 K"
         *   formatMoneyJS(100000, 'CNY') -> "¥ 200.00"
         *   formatMoneyJS(100000, 'USD') -> "$ 28.57"
         */
        window.formatMoneyJS = function (amount, targetCurrency, withSymbol) {
            // 参数处理
            if (typeof targetCurrency === 'boolean') {
                withSymbol = targetCurrency;
                targetCurrency = null;
            }
            withSymbol = withSymbol !== false;
            
            // 空值处理
            if (amount === null || amount === undefined || amount === '') {
                amount = 0;
            }

            // 已经是带货币符号的字符串则直接返回
            if (typeof amount === 'string' && /[K₹¥$€£]|MMK|USD|CNY/.test(amount)) {
                return amount;
            }

            var config = window.currencyConfig || {};
            
            // 获取目标货币
            var currency = targetCurrency || config.currency || 'MMK';
            
            // 汇率配置
            var rates = config.rates || { MMK: 1, CNY: 500, USD: 3500 };
            
            // 验证货币，未知货币 fallback 到 MMK
            if (!rates[currency]) {
                currency = 'MMK';
            }

            var number = parseFloat(String(amount).replace(/,/g, ''));
            if (isNaN(number)) {
                number = 0;
            }

            // 根据目标货币计算并格式化
            var symbolMap = { MMK: 'K', CNY: '¥', USD: '$' };
            var symbol = symbolMap[currency] || 'K';
            var formatted;

            if (currency === 'MMK') {
                formatted = Math.round(number).toLocaleString('en-US');
                return withSymbol ? formatted + ' K' : formatted;
            }

            var converted = number / rates[currency];
            
            if (currency === 'CNY') {
                formatted = converted.toFixed(2);
                return withSymbol ? '¥ ' + formatted : formatted;
            }

            if (currency === 'USD') {
                formatted = converted.toFixed(2);
                return withSymbol ? '$ ' + formatted : formatted;
            }

            // Fallback
            formatted = Math.round(number).toLocaleString('en-US');
            return withSymbol ? formatted + ' K' : formatted;
        };
    })();
    
     
    // Scroll to active item in sidebar
    $(document).ready(function() {
        const sidebar = document.querySelector('.sidebar .nav');
        let activeItem = sidebar.querySelector('.nav-item.active');

        if (activeItem) {
            const sidebarRect = sidebar.getBoundingClientRect();
            const itemRect = activeItem.getBoundingClientRect();

            // Calculate offset so the active item is centered
            const offset = activeItem.offsetTop - sidebar.offsetHeight / 2 + activeItem.offsetHeight / 2;

            sidebar.scrollTop = offset;
        }
    });

    setTimeout(() => {
        
        $(document).ready(function() {
            var targetNode = document.querySelector('thead');

            // Page without thead (e.g. Pay Optional form) — skip observer
            if (!targetNode) {
                return;
            }

            // Apply initial styles
            $('th[data-field="operate"]').addClass('action-column');

            // Create an observer instance linked to the callback function
            var observer = new MutationObserver(function(mutationsList, observer) {
                for (var mutation of mutationsList) {
                    if (mutation.type === 'childList') {
                        // Reapply the class when changes are detected
                        $('th[data-field="operate"]').addClass('action-column');
                    }
                }
            });

            // Start observing the target node for configured mutations
            observer.observe(targetNode, { childList: true, subtree: true });
        });

    }, 500);
    

    // razorpay-payment-button
    setTimeout(() => {
        $('.razorpay-payment-button').addClass('btn btn-info');
    }, 100);



    // document.addEventListener("DOMContentLoaded", function () {
    //     var isMobile = window.matchMedia("only screen and (max-width: 768px)").matches;
    //     var table = document.getElementsByClassName('reorder-table-row');

    //     if (table) {
    //         if (isMobile) {
    //             table.removeAttribute('data-reorderable-rows');
    //         } else {
    //             table.setAttribute('data-reorderable-rows', 'true');
    //         }
    //     }
    //     // Initialize the table
    //     $('.reorder-table-row').bootstrapTable();
    // });



    document.addEventListener("DOMContentLoaded", function() {
        // Add the event listener for the button to initiate the payment
        setTimeout(() => {
            
            $('#razorpay-button').click(function (e) {
                e.preventDefault(); 
                let baseUrl = window.location.origin;
                var order_id = '';
                var paymentTransactionId = '';

                $.ajax({
                    type: "post",
                    url: baseUrl + '/subscriptions/create/razorpay/order-id',
                    data: {
                        amount: $('.bill_amount').val(), // Amount is in currency subunits. Default currency is MMK. Hence, 100 refers to 100 MMK
                        currency : "{{ $system_settings['currency_code'] ?? 'MMK' }}",

                        type : $('.type').val(),
                        package_type : $('.package_type').val(),
                        package_id : $('.package_id').val(),
                        upcoming_plan_type : $('.upcoming_plan_type').val(),
                        subscription_id : $('.subscription_id').val(),
                        feature_id : $('.feature_id').val(),
                        end_date : $('.end_date').val(),
                        
                    },
                    success: function (response) {
                        if (response.data) {
                            order_id = response.data.order.id;
                            paymentTransactionId = response.data.paymentTransaction.id;
                           
                            var options = {
                                "key": "{{ $paymentConfiguration->api_key ?? '' }}", // Enter the Key ID generated from the Dashboard
                                "amount": $('.bill_amount').val() * 100, // Amount is in currency subunits. Default currency is MMK. Hence, 100 refers to 100 MMK
                                "currency": "{{ $system_settings['currency_code'] ?? 'MMK' }}",
                                "name": "{{ $system_settings['system_name'] ?? 'eSchool-Saas' }}",
                                "description": "Razorpay",
                                "order_id": order_id,
                                "handler": function(response) {
                                    // Set the response data in the form
                                    $('.razorpay_payment_id').val(response.razorpay_payment_id);
                                    $('.razorpay_signature').val(response.razorpay_signature);
                                    $('.razorpay_order_id').val(response.razorpay_order_id);
                                    $('.paymentTransactionId').val(paymentTransactionId);

                                    // Submit the form
                                    document.querySelector('.razorpay-form').submit();
                                }
                            };

                            var rzp1 = new Razorpay(options);
                            rzp1.open();
                        } else {
                            Swal.fire({icon: 'error', text: response.message});
                        }
                    }
                    
                });
                
                
            });

        }, 100);

    });

</script>

{{-- Search sidebar menu --}}
<script>
    $(document).ready(function() {
        $("#menu-search,#menu-search-mini").on("keyup", function() {
            var value = $(this).val().toLowerCase();

            $(".nav > li").each(function() {
                var parent = $(this);
                var parentText = parent.children('a').text().toLowerCase();
                var parentMatch = parentText.indexOf(value) > -1;

                // Check if any child items match
                var childMatch = false;
                parent.find('.sub-menu > li').each(function() {
                    var child = $(this);
                    var childText = child.text().toLowerCase();
                    if (childText.indexOf(value) > -1) {
                        child.show();
                        childMatch = true;
                    } else {
                        child.hide();
                    }
                });

                // Show parent if any child matches, otherwise hide
                if (parentMatch || childMatch) {
                    parent.show();
                    if (childMatch) {
                        parent.children('.sub-menu').slideDown();
                    }
                } else {
                    parent.hide();
                }
            });
        });

    });

    $('.navbar-toggler').click(function (e) { 
        e.preventDefault();

        var updatedClasses = $('body').hasClass('sidebar-icon-only');

        if (!updatedClasses) {
            $('.menu-search').addClass('d-none');
        } else {
            $('.menu-search').removeClass('d-none');
        }
    });

    // Scroll to active item in sidebar
    // document.addEventListener("DOMContentLoaded", function() {
    //     try {
    //         const sidebar = document.querySelector('.sidebar .nav'); // correct selector for the sidebar navigation
    //         if (!sidebar) return; // Exit if sidebar not found
            
    //         // First check for active nav-item
    //         let activeItem = sidebar.querySelector('.nav-item.active'); 
            
    //         // If no active nav-item is found, check for active links in sub-menus
    //         if (!activeItem) {
    //             const activeLink = sidebar.querySelector('.nav-link.active');
    //             if (activeLink) {
    //                 // If active link is in sub-menu, get its parent collapse and nav-item
    //                 activeItem = activeLink.closest('.nav-item');
                    
    //                 // Also expand the parent menu if it's in a collapse
    //                 const parentCollapse = activeLink.closest('.collapse');
    //                 if (parentCollapse) {
    //                     parentCollapse.classList.add('show');
    //                 }
    //             }
    //         }
            
    //         if (!activeItem) return; // Exit if no active item found
            
    //         // Calculate offset so the active item is centered
    //         const offset = activeItem.offsetTop - sidebar.offsetHeight / 2 + activeItem.offsetHeight / 2;
    //         if (offset > 0) {
    //             sidebar.scrollTop = offset;
    //         }
    //     } catch (err) {
    //         console.error("Error in sidebar scroll:", err);
    //     }
    // });

    

</script>

<script>
    // Function to handle image errors
    function handleImageError(image) {
        // Prevent infinite loops by checking if already processed
        if (image.hasAttribute('data-error-handled')) {
            return;
        }

        // Temporarily disconnect observer to prevent triggering mutations
        imageObserver.disconnect();

        try {
            if (image.classList.contains('custom-default-image')) {
                if (image.getAttribute('data-custom-image') != null) {
                    image.src = image.getAttribute('data-custom-image');
                } else {
                    image.src = "{{ asset('/assets/no_image_available.jpg') }}";
                }
            } else {
                image.src = "{{ asset('/assets/no_image_available.jpg') }}";
            }

            // Mark as processed to prevent duplicate handling
            image.setAttribute('data-error-handled', 'true');
        } finally {
            // Reconnect observer after changes
            imageObserver.observe(document, {
                childList: true,
                subtree: true
            });
        }
    }

    // Create a MutationObserver to watch for DOM changes
    const imageObserver = new MutationObserver((mutationsList) => {
        mutationsList.forEach((mutation) => {
            if (mutation.addedNodes) {
                mutation.addedNodes.forEach((node) => {
                    // Check if the added node is an image element
                    if (node instanceof HTMLImageElement && !node.hasAttribute('data-error-handled')) {
                        node.addEventListener('error', () => {
                            handleImageError(node);
                        });
                    }
                });
            }
        });
    });

    // Initialize: Handle existing images on page load and start observing
    document.addEventListener('DOMContentLoaded', function() {
        // Add error handlers to all existing images
        document.querySelectorAll('img').forEach(function(img) {
            if (!img.hasAttribute('data-error-handled')) {
                img.addEventListener('error', function() {
                    handleImageError(img);
                });
            }
        });

        // Start observing for new images added to the DOM
        imageObserver.observe(document, {
            childList: true,
            subtree: true
        });
    });
</script>