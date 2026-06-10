// Bootstrap Custom Column Formatters
// noinspection JSUnusedGlobalSymbols

/**
 * 格式化货币显示
 * @param {*} value - 金额值
 * @param {string} currency - 货币代码 (MMK/CNY/USD)，默认 MMK
 * @returns {string} 格式化后的金额字符串
 */
window.formatMoneyJS = function(value, currency) {
    // 处理无效值
    if (value === null || value === undefined || value === '' || isNaN(parseFloat(value))) {
        return '0 K';
    }

    // 转换为数字
    var amount = parseFloat(value);

    // 默认货币为 MMK
    var currencyCode = currency || 'MMK';

    // 未知货币统一使用 MMK
    if (currencyCode !== 'CNY' && currencyCode !== 'USD') {
        currencyCode = 'MMK';
    }

    // 根据货币类型格式化
    if (currencyCode === 'MMK') {
        // 缅甸缅币: 100,000 K
        var intPart = Math.floor(amount);
        var formatted = intPart.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return formatted + ' K';
    } else if (currencyCode === 'CNY') {
        // 人民币: ¥ 100,000.00
        return '¥ ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    } else {
        // 美元: $ 100,000.00
        return '$ ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
};

function fileFormatter(value, row) {
    if (row.file && row.file.length) {
        let file_upload = "<br><h6>" + window.trans["File Upload"] + "</h6>";
        let youtube_link = "<br><h6>" + window.trans["YouTube Link"] + "</h6>";
        let video_upload = "<br><h6>" + window.trans["Video Upload"] + "</h6>";
        let other_link = "<br><h6>" + window.trans["Other Link"] + "</h6>";

        let file_upload_counter = 1;
        let youtube_link_counter = 1;
        let video_upload_counter = 1;
        let other_link_counter = 1;

        $.each(row.file, function (key, data) {
            //1 = File Upload , 2 = YouTube , 3 = Uploaded Video , 4 = Other
            if (data.type == 1) {
                // 1 = File Upload
                file_upload += "<a href='" + data.file_url + "' target='_blank' >" + file_upload_counter + ". File Upload</a><br>";
                file_upload_counter++;
            } else if (data.type == 2) {
                // 2 = YouTube Link
                youtube_link += "<a href='" + data.file_url + "' target='_blank' >" + youtube_link_counter + ". YouTube Link</a><br>";
                youtube_link_counter++;
            } else if (data.type == 3) {
                // 3 = Uploaded Video
                video_upload += "<a href='" + data.file_url + "' target='_blank' >" + video_upload_counter + ". Video Upload</a><br>";
                video_upload_counter++;
            } else if (data.type == 4) {
                // 4 = Other Link
                other_link += "<a href='" + data.file_url + "' target='_blank' class='text-truncate'>" + other_link_counter + ". Link</a><br>";
                other_link_counter++;
            }
        })
        let html = "";
        if (file_upload_counter > 1) {
            html += file_upload;
        }

        if (youtube_link_counter > 1) {
            html += youtube_link;
        }

        if (video_upload_counter > 1) {
            html += video_upload;
        }

        if (other_link_counter > 1) {
            html += other_link;
        }

        return html;
    } else {
        return " - ";
    }
}


function packageFeatureFormatter(value, row) {
    let html = '';
    html += "<ul>";
    $.each(row.package_feature, function (value, data) {
        html += "<li>" + data.feature.name + "</li>";
    });
    html += "</ul>";
    return html;
}

function yesAndNoStatusFormatter(value) {
    if (value) {
        return "<span class='badge badge-success'>" + window.trans["Yes"] + "</span>";
    } else {
        return "<span class='badge badge-danger'>" + window.trans["No"] + "</span>";
    }
}

function actionColumnFormatter(value, row, index) {
    if (index == 0 || index == 1) {
        $(".fixed-table-body").css("height", '280px');
    } else {
        $(".fixed-table-body").css("height", '100%');
    }
    return '<div class="action-column-menu">' + value + '</div>';
}


function packageTypeFormatter(value, row) {
    if (typeof row.type !== 'undefined') {
        if (row.type) {
            return "<span class='badge badge-primary'>" + window.trans["postpaid"] + "</span>";
        } else {
            return "<span class='badge badge-info'>" + window.trans["prepaid"] + "</span>";
        }
    } else {
        if (row.subscription.package_type) {
            return "<span class='badge badge-primary'>" + window.trans["postpaid"] + "</span>";
        } else {
            return "<span class='badge badge-info'>" + window.trans["prepaid"] + "</span>";
        }
    }

}

function descriptionFormatter(value, row) {
    let html = '';
    if (value) {
        html = '<div class="bootstrap-table-description" data-toggle="modal" data-target="#descriptionModal"><a href="javascript:void(0)">' + value + '</a></div>';
    }
    return html;
}

function diaryFormatter(value, row) {
    let html = '';
    if (value) {
        html = '<div class="bootstrap-table-description" data-toggle="modal" data-target="#diaryModal">' + value + '</div>';
    }
    return html;
}

function diaryTypeFormatter(value, row) {
    if (value == 'positive') {
        return "<span class='badge badge-success'>" + window.trans["positive"] + "</span>";
    } else {
        return "<span class='badge badge-danger'>" + window.trans["negative"] + "</span>";
    }
}

function leaveStatusFormatter(value) {
    if (value == 0) {
        return "<span class='badge badge-warning'>" + window.trans["pending"] + "</span>";
    } else if (value == 1) {
        return "<span class='badge badge-success'>" + window.trans["approved"] + "</span>";
    } else {
        return "<span class='badge badge-danger'>" + window.trans["rejected"] + "</span>";
    }
}

function userTypeFormatter(value, row) {
    let html = '';
    if (row.user_status) {
        if (row.user_status.status == 0) {
            html = '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" value="1">' + window.trans['enable'] + '<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label"><input type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" checked value="0">' + window.trans['disable'] + '<i class="input-helper"></i></label></div></div>';
        } else {
            html = '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input checked required type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" value="1">' + window.trans['enable'] + '<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label"><input type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" value="0">' + window.trans['disable'] + '<i class="input-helper"></i></label></div></div>';
        }
    } else {
        if (row.deleted_at) {
            html = '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" value="1">' + window.trans['enable'] + '<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label"><input type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" checked value="0">' + window.trans['disable'] + '<i class="input-helper"></i></label></div></div>';
        } else {
            html = '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input checked required type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" value="1">' + window.trans['enable'] + '<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label"><input type="radio" class="type form-check-input" id="' + row.id + '" name="user_status[' + row.id + '][type]" value="0">' + window.trans['disable'] + '<i class="input-helper"></i></label></div></div>';
        }
    }


    return html;
}

function featurePermissionFormatter(value, row) {
    let html = '';
    html += '<ul>';

    $.each(row.permission, function (value, data) {
        html += '<li>' + data + '</li>';
    });
    html += '</ul>';
    return html;
}

function subscriptionStatusFormatter(value, row) {
    // 1 => Current Cycle, 2 => Success, 3 => Over Due, 4 => Failed, 5 => Pending, 6 => Next Billing Cycle
    let html = '';
    if (value == 'Current Cycle') {
        html = "<span class='badge badge-primary'>" + window.trans["current_cycle"] + "</span>";
    } else if (value == 'Paid' || row.amount == 'Paid') {
        html = "<span class='badge badge-success'>" + window.trans["paid"] + "</span>";
    } else if (value == 'Over Due') {
        html = "<span class='badge badge-danger-light'>" + window.trans["over_due"] + "</span>";
    } else if (value == 'Failed') {
        html = "<span class='badge badge-danger'>" + window.trans["failed"] + "</span>";
    } else if (value == 'Pending') {
        html = "<span class='badge badge-warning'>" + window.trans["pending"] + "</span>";
    } else if (value == 'Next Billing Cycle') {
        html = "<span class='badge badge-info'>" + window.trans["next_billing_cycle"] + "</span>";
    } else if (value == 'Unpaid') {
        html = "<span class='badge badge-danger'>" + window.trans["unpaid"] + "</span>";
    } else if (value == 'Bill Not Generated') {
        html = "<span class='badge badge-dark'>" + window.trans["bill_not_generated"] + "</span>";
    }
    return html;

}

function planDetailFormatter(value, row) {
    let html = '';
    html += row.plan;
    html += '<div class="mt-2"><small class="text-info">' + row.billing_cycle + '</small></div>';
    return html;

}

function salaryInputFormatter(value, row) {
    let html;
    if (value) {
        html = '<input type="number" min="0" required name="basic_salary[' + row.id + ']" class="form-control" readonly value="' + value + '">';
    } else {
        html = '<input type="number" required min="0" name="basic_salary[' + row.id + ']" class="form-control" readonly value="0">';
    }
    return html;
}

function netSalaryInputFormatter(value, row) {
    let html = '';
    if (value) {
        value = parseFloat(value);
        html = '<input type="number" min="0" required name="net_salary[' + row.id + ']" class="form-control" value="' + value.toFixed(2) + '">';
    } else {
        html = '<input type="number" required min="0" name="net_salary[' + row.id + ']" class="form-control" value="0">';
    }

    html += paid_leave = '<input type="hidden" required name="paid_leave[' + row.id + ']" class="form-control" value="' + row.paid_leaves + '">'

    return html;
}

function salaryStatusFormatter(value) {
    let html;
    if (value == 1) {
        html = '<div class="badge badge-success badge-pill">' + window.trans['paid'] + '</div>';
    } else {
        html = '<div class="badge badge-danger badge-pill">' + window.trans['unpaid'] + '</div>';
    }
    return html;
}

function assignmentFileFormatter(value, row) {
    return "<a target='_blank' href='" + row.file + "'>" + row.name + "</a>";
}


function assignmentSubmissionStatusFormatter(value, row) {
    let html;
    // 0 = Pending/In Review , 1 = Accepted , 2 = Rejected , 3 = Resubmitted
    if (row.status === 0) {
        html = "<span class='badge badge-warning'>" + window.trans['Pending'] + "</span>";
    } else if (row.status === 1) {
        html = "<span class='badge badge-success'>" + window.trans['Accepted'] + "</span>";
    } else if (row.status === 2) {
        html = "<span class='badge badge-danger'>" + window.trans['Rejected'] + "</span>";
    } else if (row.status === 3) {
        html = "<span class='badge badge-warning'>" + window.trans['Resubmitted'] + "</span>";
    }
    return html;
}

function assignmentSubmissionPointsFormatter(value, row) {
    if (row.assignment.points == null || row.assignment.points == undefined) return "-";
    if (row.points) {
        return "<input type='number' style='z-index: 999;' name='assignment_data[" + row.no + "][points]' id='points-" + row.id + "' class='form-control' max='" + row.assignment.points + "' min='0' value='" + value + "' />"
    } else {
        return "<input type='number' style='z-index: 999;' name='assignment_data[" + row.no + "][points]' id='points-" + row.id + "' class='form-control' max='" + row.assignment.points + "' min='0' value='' />"
    }
}

function assignmentSubmissionStatusUpdateFormatter(value, row) {
    let html = "<input type='hidden' value=" + row.id + " name='assignment_data[" + row.no + "][id]'><input type='hidden' name='assignment_data[" + row.no + "][student_id]' value=" + row.student_id + ">"
    if (row.status == 1) {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" id="accepted-' + row.id + '" name="assignment_data[' + row.no + '][status]" value="1" checked>Accept<i class="input-helper"></i></label></div><div class="form-check mr-2"><label class="form-check-label"><input type="radio" class="type form-check-input" name="assignment_data[' + row.no + '][status]" value="2">Rejected<i class="input-helper"></i></label></div></div>';
    } else if (row.status == 2) {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" id="accepted-' + row.id + '" name="assignment_data[' + row.no + '][status]" value="1">Accept<i class="input-helper"></i></label></div><div class="form-check mr-2"><label class="form-check-label"><input type="radio" class="type form-check-input" name="assignment_data[' + row.no + '][status]" value="2" checked>Rejected<i class="input-helper"></i></label></div></div>';
    } else {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input data-id="' + row.user_id + '" required type="radio" class="type form-check-input" id="accepted-' + row.id + '" name="assignment_data[' + row.no + '][status]" value="1" >Accept<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label"><input type="radio" data-id="' + row.user_id + '" class="type form-check-input" name="assignment_data[' + row.no + '][status]" value="2">Rejected<i class="input-helper"></i></label></div></div>';
    }
    return html;
}

function assignmentSubmissionFeedbackUpdateFormatter(value, row) {
    if (row.feedback) {
        return "<input type='text' id='feedback-" + row.id + "' name='assignment_data[" + row.no + "][feedback]' class='form-control' value='" + value + "' />"
    } else {
        return "<input type='text' id='feedback-" + row.id + "' name='assignment_data[" + row.no + "][feedback]' class='form-control' value='' />"
    }
}

function imageFormatter(value) {
    if (value) {
        return "<a data-toggle='lightbox' href='" + value + "' class=''><img src='" + value + "' class=''  alt='image'  onerror='onErrorImage(event)' /></a>";
    } else {
        return '-'
    }
}

function StudentNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.user.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.user.full_name + '</h6> <small class="text-muted"> ' + row.user.email + ' </small> </div> </div>';
    return html;
}

function TeacherNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.full_name + '</h6> <small class="text-muted"> ' + row.email + ' </small> </div> </div>';
    return html;
}

function GuardianNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.full_name + '</h6> <small class="text-muted"> ' + row.email + ' </small> </div> </div>';
    return html;
}

function StudentGuardianNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' 
        + imageFormatter(row.guardian.image) 
        + ' <div class="ms-3"> <h6 class="mb-0">' 
        + row.guardian.full_name 
        + '</h6> <small class="text-muted">'
        + row.guardian.email + '<br>'
        + (row.guardian.mobile ? + row.guardian.mobile : '') 
        + ' </small> </div> </div>';
    return html;
}

function AssignmentSubmissionStudentNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.student.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.student.full_name + '</h6> <small class="text-muted"> ' + row.student.email + ' </small> </div> </div>';
    return html;
}

function NotificationUserNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.full_name + '</h6> <small class="text-muted"> ' + row.email + ' </small> </div> </div>';
    return html;
}

function StaffNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.full_name + '</h6> <small class="text-muted"> ' + row.email + ' </small> </div> </div>';
    return html;
}
function CreatedByNameFormatter(value, row) {
    if (row.created_by != null) {
        let html = '';
        html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.created_by.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.created_by.full_name + '</h6> <small class="text-muted"> ' + row.created_by.email + ' </small> </div> </div>';
        return html;
    }
}
function DriverNameFormatter(value, row) {
    let html = '';
    if (row.driver) {
        html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.driver.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.driver.full_name + '</h6> <small class="text-muted"> ' + row.driver.email + ' </small> </div> </div>';
    } else {
        html = '-';
    }
    return html;
}
function HelperNameFormatter(value, row) {
    let html = '';
    if (row.helper) {
        html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.helper.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.helper.full_name + '</h6> <small class="text-muted"> ' + row.helper.email + ' </small> </div> </div>';
    } else {
        html = '-';
    }
    return html;
}

function SchoolNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.logo) + ' <div class="ms-3"> <h6 class="mb-0">' + row.name + '</h6> <small class="text-muted"> ' + row.support_email + ' </small> </div> </div>';
    return html;
}

function SchoolNameSubscriptionFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.school.logo) + ' <div class="ms-3"> <h6 class="mb-0">' + row.school.name + '</h6> <small class="text-muted"> ' + row.school.support_email + ' </small> </div> </div>';
    return html;
}

function layoutFormatter(value, row) {
    if (value) {
        html = "<span class='badge badge-success'>" + window.trans['Yes'] + "</span>";
    } else {
        html = "<span class='badge badge-danger'>" + window.trans['No'] + "</span>";
    }
    return html;
}

function studentImageFormatter(value, row) {
    return '<input type="file" name="student_image[' + row.user.id + ']" accept="image/jpg,image/png,image/jpeg,image/svg">';
}

function guardianImageFormatter(value, row) {
    return '<input type="file" name="guardian_image[' + row.guardian.id + ']" accept="image/jpg,image/png,image/jpeg,image/svg">';
}

function dateFormatter(value) {
    if (value) {
        return moment(value).format(date_format);
    }
    return '';
}

function timeFormatter(value) {
    if (value) {
        return moment(value, 'HH:mm:ss').format(time_format);
    }
    return '';
}

function dateTimeFormatter(value) {
    if (value) {
        return moment(value).format(date_time_format);
    }
    return '';
}

function examStatusFormatter(value, row) {
    if (value == 'Upcoming') {
        return '<div class="badge badge-warning text-dark badge-pill">' + window.trans['Upcoming'] + '</div>';
    } else if (value == 'On Going') {
        return '<div class="badge badge-info badge-pill">' + window.trans['On Going'] + '</div>';
    } else {
        return '<div class="badge badge-success badge-pill">' + window.trans['Completed'] + '</div>';
    }
}

function schoolNameFormatter(value, row) {
    let html = '';
    html += '<ul>';
    $.each(row.support_school, function (value, data) {
        html += '<li>' + data.school.name + '</li>';
    });
    html += '</ul>';
    return html;
}

function schoolAdminFormatter(value, row) {
    let html = '';
    html += row.user.full_name;
    html += '<p class="mt-1 text-facebook"><small>' + row.user.email + '</small></p>';
    return html;
}

function linkFormatter(value, row) {
    if (row.link) {
        return "<a href='" + row.link + "' target='_blank'>" + row.link + "</a>";
    } else {
        return '-'
    }
}

function typeFormatter(value, row) {
    if (value == 1) {
        return '<div class="badge badge-primary badge-pill">' + window.trans['app'] + '</div>'
    } else if (value == 2) {
        return '<div class="badge badge-info badge-pill">' + window.trans['web'] + '</div>'
    } else if (value == 3) {
        return '<div class="badge badge-success badge-pill">' + window.trans['both'] + '</div>'
    } else if (value == 4) {
        return '<div class="badge badge-warning badge-pill">' + window.trans['student_web'] + '</div>'
    } else {
        return '<div class="badge badge-secondary badge-pill">' + value + '</div>'
    }
}

function examTimetableFormatter(value, row) {
    let html = []
    if (row.timetable.length != null) {
        $.each(row.timetable, function (key, timetable) {
            html.push('<p>' + timetable.subject.name + '(' + timetable.subject.type + ')  - ' + timetable.total_marks + '/' + timetable.passing_marks + ' - ' + timetable.start_time + ' - ' + timetable.end_time + ' - ' + timetable.date + '</p>')
        });
    }
    return html.join('')
}

function examSubjectFormatter(value, row) {
    if (row.subject_name) {
        return row.subject_name;
    } else {
        return $('#subject_id :selected').text();
    }
}

function examStudentNameFormatter(value, row) {
    return "<input type='hidden' name='exam_marks[" + row.no + "][student_id]' class='form-control' value='" + row.id + "' />" + row.student_name
}

function obtainedMarksFormatter(value, row) {
    if (row.obtained_marks != null) {
        return "<input type='hidden' name='exam_marks[" + row.no + "][exam_marks_id]' class='form-control' value='" + row.exam_marks_id + "' />" +
            "<input type='number' required max='" + row.total_marks + "'  name='exam_marks[" + row.no + "][obtained_marks]' class='form-control' min='0' value='" + row.obtained_marks + "' />" + "<input type='hidden' name='exam_marks[" + row.no + "][total_marks]' class='form-control' value='" + parseInt(row.total_marks) + "' />"
    } else {
        return "<input type='number' required max='" + row.total_marks + "' name='exam_marks[" + row.no + "][obtained_marks]' class='form-control' min='0' value='" + ' ' + "' />" + "<input type='hidden' name='exam_marks[" + row.no + "][total_marks]' class='form-control' value='" + parseInt(row.total_marks) + "' />"
    }
}

function teacherReviewFormatter(value, row) {
    if (row.teacher_review) {
        return "<textarea name='exam_marks[" + row.no + "][teacher_review]' class='form-control'>" + row.teacher_review + "</textarea>"
    } else {
        return "<textarea name='exam_marks[" + row.no + "][teacher_review]' class='form-control'>" + ' ' + "</textarea>"
    }
}

function coreSubjectFormatter(value, row) {

    let core_subject_count = 1;
    let html = "<div style='line-height: 20px;'>";
    if (row.core_subjects.length) {
        $.each(row.core_subjects, function (key, row) {
            html += core_subject_count + ". " + row.name_with_type + "<br>"
            core_subject_count++;
        })
    }
    html += "</div>";
    return html;
}

function electiveSubjectFormatter(value, row) {
    let html = "<div style='line-height: 20px;'>";
    $.each(row.elective_subject_groups, function (key, group) {
        let elective_subject_count = 1;
        html += "<b>" + window.trans['group'] + " " + (key + 1) + "</b><br>";
        $.each(group.subjects, function (key, subject) {
            html += elective_subject_count + ". " + subject.name + " - " + window.trans[subject.type] + "<br>"
            elective_subject_count++;
        })
        html += "<b>" + window.trans['total_subjects'] + " : </b>" + group.total_subjects + "<br>"
        html += "<b>" + window.trans['total_selectable_subjects'] + " : </b>" + group.total_selectable_subjects + "<br><br>"
    })
    html += "</div>";
    return html;
}

function feesTypeFormatter(value, row) {
    let html = "<ol>";
    if (row.fees_class_type?.length) {
        $.each(row.fees_class_type, function (key, value) {
            // MMK 等值金额：优先 fee_amount_mmk，fallback amount
            var mmkAmount = (value.fee_amount_mmk && parseFloat(value.fee_amount_mmk) !== 0)
                ? parseFloat(value.fee_amount_mmk)
                : parseFloat(value.amount || 0);

            html += "<li>" + value.fees_type_name + " - " + formatMoneyJS(mmkAmount);

            if (value.optional) {
                html += "<small class='ml-1 badge badge-danger rounded-pill p-1'>" + window.trans["optional"] + "</small>";
            } else {
                // 仅对 compulsory fees 显示多币种信息
                var currency = value.fee_currency || 'MMK';
                if (currency !== 'MMK' && currency) {
                    var originalAmount = (value.fee_original_amount && parseFloat(value.fee_original_amount) !== 0)
                        ? parseFloat(value.fee_original_amount)
                        : parseFloat(value.amount || 0);
                    var exchangeRate = value.fee_exchange_rate_snapshot || 1;
                    var formattedOriginal = originalAmount.toFixed(2) + ' ' + currency;
                    html += "<br><small class='text-muted'>"
                        + formattedOriginal
                        + " @ " + parseFloat(exchangeRate).toFixed(2)
                        + "</small>";
                }
            }
            html += "</li>";
        });
    }
    html += "</ol>";
    return html

}

function FeesTransactionUserNameFormatter(value, row) {
    let html = '';
    html = '<div class="d-flex align-items-center"> ' + imageFormatter(row.user.image) + ' <div class="ms-3"> <h6 class="mb-0">' + row.user.full_name + '</h6> <small class="text-muted"> ' + row.user.email + ' </small> </div> </div>';
    return html;
}

function feesTransactionParentGateway(value, row) {
    if (row.payment_gateway == "Stripe" || row.payment_gateway == "stripe") {
        return "<span class='badge badge-primary'>" + window.trans['Stripe'] + "</span>";
    } else if (row.payment_gateway == 'Cash' || row.payment_gateway == 'cash') {
        return "<span class='badge badge-success'>" + window.trans['cash'] + "</span>";
    } else if (row.payment_gateway == 'Cheque' || row.payment_gateway == 'cheque') {
        return "<span class='badge badge-info'>" + window.trans['cheque'] + "</span>";
    } else if (row.payment_gateway == 'Razorpay' || row.payment_gateway == 'razorpay') {
        return "<span class='badge badge-dark'>" + window.trans['Razorpay'] + "</span>";
    } else if (row.payment_gateway == 'Flutterwave' || row.payment_gateway == 'flutterwave') {
        return "<span class='badge badge-dark'>" + window.trans['Flutterwave'] + "</span>";
    } else {
        return "-";
    }
}

function subscriptionTransactionParentGateway(value, row) {
    if (row.payment_gateway == "Stripe") {
        return "<span class='badge badge-primary'>" + window.trans['Stripe'] + "</span>";
    } else if (row.payment_gateway == 'Cash') {
        return "<span class='badge badge-success'>" + window.trans['cash'] + "</span>";
    } else if (row.payment_gateway == 'Cheque') {
        return "<span class='badge badge-info'>" + window.trans['cheque'] + "</span>";
    } else if (row.payment_gateway == 'Razorpay') {
        return "<span class='badge badge-dark'>" + window.trans['Razorpay'] + "</span>";
    } else if (row.payment_gateway == 'Flutterwave') {
        return "<span class='badge badge-dark'>" + window.trans['Flutterwave'] + "</span>";
    } else {
        return "-";
    }
}

function transactionPaymentStatus(value, row) {
    if (row.payment_status == 'succeed' || row.amount == 0) {
        return "<span class='badge badge-success'>" + window.trans["Success"] + "</span>";
    } else if (row.payment_status == 'pending') {
        return "<span class='badge badge-warning'>" + window.trans["pending"] + "</span>";
    } else if (row.payment_status == 'failed') {
        return "<span class='badge badge-danger'>" + window.trans["failed"] + "</span>";
    } else {
        return "<span class='badge badge-warning'>" + window.trans["pending"] + "</span>";
    }
}

function questionTypeFormatter(value, row) {
    if (row.question_type) {
        return "<span class='badge badge-secondary'>" + window.trans["equation_based"] + "</span>"
    } else {
        return "<span class='badge badge-info'>" + window.trans["optionsimple_question"] + " < /span>"
    }
}

function optionsFormatter(value, row) {
    let html = '';
    $.each(row.options, function (index, value) {
        html += "<div class='row'>";
        html += "<div class= 'col-md-1 text-center'><i class='fa fa-arrow-right small' aria-hidden='true'></i></div><div class='col-md-6'>" + value.option + "</div><br>"
        html += "</div>";
    });
    return html;
}

function questionFormatter(value, row) {
    if (row.question){
        return "<div class='equation-editor-inline' contenteditable=false>" + row.question + "</div>";
    }
    return '-';
}

function answersFormatter(value, row) {
    let html = '';
    $.each(row.answers, function (index, value) {
        html += "<div class='row'>";
        html += "<span class= 'col-md-1 text-center'><i class='fa fa-arrow-right small' aria-hidden='true'></i></span><div class='col-md-6'>" + value.answer + "</div><br>"
        html += "</div>";
    });
    return html;
}

function bgColorFormatter(value, row) {
    // Convert bg color to RGB to check brightness
    let color = row.bg_color;
    let r, g, b;

    // Handle hex color
    if (color.startsWith('#')) {
        r = parseInt(color.substr(1, 2), 16);
        g = parseInt(color.substr(3, 2), 16);
        b = parseInt(color.substr(5, 2), 16);
    }
    // Handle rgb/rgba color
    else if (color.startsWith('rgb')) {
        let nums = color.match(/\d+/g);
        r = parseInt(nums[0]);
        g = parseInt(nums[1]);
        b = parseInt(nums[2]);
    }

    // Calculate brightness using relative luminance formula
    let brightness = (r * 299 + g * 587 + b * 114) / 1000;

    // Use white text for dark backgrounds, black for light
    let textColor = brightness < 128 ? '#ffffff' : '#000000';

    // Add box shadow for white/very light backgrounds
    let boxShadow = '';
    if (brightness > 240) { // Very light color
        boxShadow = 'box-shadow: 0 0 3px rgba(0,0,0,0.2);';
    }

    return "<p style='background-color:" + row.bg_color + "; color:" + textColor + ";" + boxShadow + "' class='color-code-box'>" + row.bg_color + "</p>";
}

function formFieldDefaultValuesFormatter(value, row) {
    let html = '';
    if (row.default_values && row.default_values.length) {
        html += '<ul>';
        $.each(row.default_values, function (index, value) {
            // Choose arrow based on page direction
            let arrowClass = document.dir === 'rtl' ? 'fa-arrow-left' : 'fa-arrow-right';
            html += "<i class='fa " + arrowClass + "' aria-hidden='true'></i> " + value + "<br>";
        });
        html += '</ul>';
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}

function formFieldOtherValueFormatter(value, row) {
    let otherObj = JSON.parse(row.other);
    let html = '';
    if (otherObj) {
        html += '<ul>'
        otherObj.forEach(value => {
            Object.entries(value).forEach(([key, data]) => {
                html += "<i class='fa fa-arrow-right' aria-hidden='true'></i> " + key + ' - ' + data + '<br>'
            });
        });
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}

function addRadioInputAttendance(value, row) {

    let html = "<input type='hidden' value=" + row.id + " name='attendance_data[" + row.no + "][id]'><input type='hidden' name='attendance_data[" + row.no + "][student_id]' value=" + row.user_id + ">"
    if (row.type == 1) {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="1" checked>Present<i class="input-helper"></i></label></div><div class="form-check mr-2"><label class="form-check-label"><input type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="0">Absent<i class="input-helper"></i></label></div></div>';
    } else if (row.type == 0) {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="1">Present<i class="input-helper"></i></label></div><div class="form-check mr-2"><label class="form-check-label"><input type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="0" checked>Absent<i class="input-helper"></i></label></div></div>';
    } else {

        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input data-id="' + row.user_id + '" required type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="1" checked>Present<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label"><input type="radio" data-id="' + row.user_id + '" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="0">Absent<i class="input-helper"></i></label></div></div>';
    }
    return html;
}

function addStaffRadioInputAttendance(value, row) {

    let html = "<input type='hidden' value=" + row.id + " name='attendance_data[" + row.no + "][id]'><input type='hidden' name='attendance_data[" + row.no + "][staff_id]' value=" + row.staff_id + ">"
    if (row.type == 1) {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="1" checked>Present<i class="input-helper"></i></label></div><div class="form-check mr-2"><label class="form-check-label"><input type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="0">Absent<i class="input-helper"></i></label></div></div>';
    } else if (row.type == 0) {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input required type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="1">Present<i class="input-helper"></i></label></div><div class="form-check mr-2"><label class="form-check-label"><input type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="0" checked>Absent<i class="input-helper"></i></label></div></div>';
    } else {
        html += '<div class="d-flex"><div class="form-check mr-2"><label class="form-check-label"><input data-id="' + row.staff_id + '" required type="radio" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="1" checked>Present<i class="input-helper"></i></label></div><div class="form-check"><label class="form-check-label text-danger"><input type="radio" data-id="' + row.staff_id + '" class="type form-check-input" name="attendance_data[' + row.no + '][type]" value="0">Absent<i class="input-helper"></i></label></div></div>';
    }
    return html;
}

function addStudentIdInputAttendance(value, row) {
    return "<input type='text' class='form-control' readonly value=" + row.student_id + ">";
}

function addStaffIdInputAttendance(value, row) {
    return "<input type='text' class='form-control' readonly value=" + row.staff_id + ">";
}

function timetableDayFormatter(value) {
    let html = "<ol>";
    value.forEach(function (data) {
        html += "<li><b>" + data.title + " : </b><small>" + data.start_time + " - " + data.end_time + "</small></li>";
    })
    html += "</ol>";
    return html;
}

function teacherTimetableDayFormatter(value) {
    let html = "<ol>";
    value.forEach(function (data) {
        html += "<li><b>" + data.class_section.name + " - " + data.title + " : </b><small>" + data.start_time + " - " + data.end_time + "</small></li>";
    })
    html += "</ol>";
    return html;
}

function classTeacherListFormatter(value, row) {
    if (row.class_teachers_list.length) {
        let html = "<ol>";
        row.class_teachers_list.forEach(function (data) {
            html += "<li>" + data + " </li>";
        })
        html += "</ol>";
        return html;
    }
}

function subjectTeacherListFormatter(value, row) {
    let html = "<ol>";
    if (row.current_sem_subject_teachers_list.length) {
        row.current_sem_subject_teachers_list.forEach(function (data) {
            html += "<li>" + data + " </li>";
        })
    } else {
        row.subject_teachers_list.forEach(function (data) {
            html += "<li>" + data + " </li>";
        })
    }

    html += "</ol>";
    return html;
}

function promoteStudentResultFormatter(value, row) {
    if (value) {
        return "<input type='hidden' name='promote_data[" + row.no + "][student_id]' value='" + row.user_id + "'><div class='d-flex'><div class='form-check mr-2'><label class='form-check-label'> <input required type='radio' class='result form-check-input'  name='promote_data[" + row.no + "][result]' value='1' " + value == 1 ? "selected" : '' + ">" + window.trans["pass"] + "<i class='input-helper'></i></label></div><div class='form-check-inline'><label class='form-check-label'> <input type='radio' class='result form-check-input'  name='promote_data[" + row.no + "][result]' value='0' " + value == 0 ? "selected" : '' + ">" + window.trans["fail"] + " <i class='input-helper'></i></label></div></div>";
    } else {

        return "<input type='hidden' name='promote_data[" + row.no + "][student_id]' value='" + row.user_id + "'>" +
            "<div class='d-flex'>" +
            "<div class='form-check mr-2'>" +
            "<label class='form-check-label'>" +
            "<input required type='radio' class='result form-check-input' name='promote_data[" + row.no + "][result]' value='1' checked>" +
            window.trans["pass"] +
            " <i class='input-helper'></i></label>" +
            "</div>" +
            "<div class='form-check'>" +
            "<label class='form-check-label'>" +
            "<input type='radio' class='result form-check-input' name='promote_data[" + row.no + "][result]' value='0'>" +
            window.trans["fail"] +
            " <i class='input-helper'></i></label>" +
            "</div>" +
            "</div>";
    }
}

function promoteStudentStatusFormatter(value, row) {
    if (value) {
        return "<div class='d-flex'><div class='form-check form-check-info mr-2'><label class='form-check-label'> <input required type='radio' class='status form-check-input'  name='promote_data[" + row.no + "][status]' value='1' " + value == 1 ? "selected" : '' + ">" + window.trans["continue"] + "<i class='input-helper'></i></label></div><div class='form-check form-check-info'><label class='form-check-label'> <input type='radio' class='status form-check-input'  name='promote_data[" + row.no + "][status]' value='0' " + value == 0 ? "selected" : '' + ">" + window.trans["leave"] + " <i class='input-helper'></i></label></div></div>";
    } else {
        return "<div class='d-flex'><div class='form-check form-check-info mr-2'><label class='form-check-label'> <input required type='radio' class='status form-check-input'  name='promote_data[" + row.no + "][status]' value='1' checked>" + window.trans["continue"] + "<i class='input-helper'></i></label></div><div class='form-check form-check-info'><label class='form-check-label'> <input type='radio' class='status form-check-input'  name='promote_data[" + row.no + "][status]' value='0'>" + window.trans["leave"] + " <i class='input-helper'></i></label></div></div>";
    }
}


// function promoteStudentStudentIDFormatter(value, row) {
//     return "<input type='text' name='promote_data[" + row.no + "][student_id]' class='form-control' value='" + row.user_id + "' readonly>";
// }

function feesPaidStatusFormatter(value, row) {
    if (row.fees_status == 1) {
        return "<span class='badge badge-success'>" + window.trans["Success"] + "</span>"
    } else if (row.fees_status == 0) {
        return "<span class='badge badge-info'>" + window.trans["Partial Paid"] + "</span>"
    } else if (row.fees_status == 2) {
        return "<span class='badge badge-danger'>" + window.trans["over_due"] + "</span>"
    } else {
        return "<span class='badge badge-warning'>" + window.trans["Pending"] + "</span>";
    }
}

function feesCurrencyAmountFormatter(value, row) {
    return formatMoneyJS(value);
}

function feesPaidAmountFormatter(value, row) {
    // 主金额优先使用 amount_mmk（MMK等值），避免历史数据 amount 不一致
    var amountMmk = parseFloat(value) || 0;
    if (row.fees_paid && row.fees_paid.amount_mmk) {
        var mmk = parseFloat(row.fees_paid.amount_mmk);
        if (mmk > 0) {
            amountMmk = mmk;
        }
    }

    var html = formatMoneyJS(amountMmk);

    // 如果付款币种是 USD/CNY，显示原币信息
    var currency = (row.fees_paid && row.fees_paid.transaction_currency)
        ? row.fees_paid.transaction_currency : '';
    if (currency === 'USD' || currency === 'CNY') {
        var orig = parseFloat(row.fees_paid.original_amount) || parseFloat(value) || 0;
        var rate = parseFloat(row.fees_paid.exchange_rate_snapshot) || 1;
        html += '<br><small class="text-muted">' +
            orig.toFixed(2) + ' ' + currency + ' @ ' + rate.toFixed(2) + '</small>';
    }

    return html;
}

function optionalFeesPaidListAmountFormatter(value, row) {
    // value = optional_fees_amount（单个 optional item 的 MMK 金额）
    var amountMmk = parseFloat(value) || 0;
    var html = formatMoneyJS(amountMmk);

    // 读取整笔付款的多币种信息（优先顶层字段，fallback 嵌套 fees_paid）
    var currency = row.transaction_currency || '';
    var orig = 0;
    var rate = 1;

    if (!currency && row.fees_paid && row.fees_paid.transaction_currency) {
        currency = row.fees_paid.transaction_currency;
    }

    if (currency) {
        orig = parseFloat(row.original_amount) || parseFloat(row.fees_paid ? row.fees_paid.original_amount : null) || amountMmk;
        rate = parseFloat(row.exchange_rate_snapshot) || parseFloat(row.fees_paid ? row.fees_paid.exchange_rate_snapshot : null) || 1;
    }

    // 只有 USD/CNY 才显示第二行原币信息
    if (currency === 'USD' || currency === 'CNY') {
        html += '<br><small class="text-muted">' +
            orig.toFixed(2) + ' ' + currency + ' @ ' + rate.toFixed(2) + '</small>';
    }

    return html;
}

function manageFeesAmountFormatter(value, row) {
    return formatMoneyJS(value);
}

function classSubjectsDetailFormatter(value, row) {
    if (row.include_semesters) {
        let html = `<table class="table table-borderless">`
        $.each(row.semesters, function (index, semester) {

            let CoreSubjectsList = '';
            let no = 1;
            if (typeof row.semester_wise_core_subjects !== "undefined") {
                $.each(row.semester_wise_core_subjects, function (index, subject) {
                    if (subject.pivot.semester_id == semester.id) {

                        CoreSubjectsList += (no) + '. ' + subject.name_with_type + '<br>';
                        no++;
                    }
                });
            }

            let ElectiveSubjectsList = "";
            no = 1
            if (typeof row.semester_wise_elective_subject_groups !== "undefined") {
                $.each(row.semester_wise_elective_subject_groups, function (index, group) {
                    let subjectsList = ""
                    if (group.semester_id == semester.id) {
                        $.each(group.subjects, function (index, subject) {
                            subjectsList += (no++) + '. ' + subject.name_with_type + '<br>'

                        });
                        ElectiveSubjectsList += '<b>' + window.trans["group"] + '</b> - ' + (index + 1) + '<br>' + subjectsList + '<b>' + window.trans["total_subjects"] + '</b> : ' + group.total_subjects + '<br> <b>' + window.trans["total_selectable_subjects"] + '</b> : ' + group.total_selectable_subjects + '<br><br>';
                    }
                });
            }

            html += `<thead>
                        <tr>
                            <th scope="col"></th>
                            <th scope="col" class="text-right pr-5"><h3><u>` + semester.name + `</u></h3></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <thead>
                        <tr>
                            <th scope="col"></th>
                            <th scope="col">` + window.trans["Core Subjects"] + `</th>
                            <th scope="col">` + window.trans["elective_subject"] + `</th>
                        </tr>
                    </thead>
                    <tbody border="2">
                        <tr>
                            <th scope="row">-></th>
                            <td>` + CoreSubjectsList + `</td>
                            <td>` + ElectiveSubjectsList + `</td>
                        </tr>
                    </tbody>
                    `
        });
        html += '</table>';
        return html;
    }
}

function classSubjectsDetailFilter(value, row) {
    return row.include_semesters == 1
}

function subjectTeachersDetailFilter(value, row) {
    return row.class.include_semesters == 1
}


function SubjectTeachersDetailFormatter(value, row) {
    if (row.class.include_semesters) {
        let html = `<table class="table table-borderless">`
        $.each(row.subject_teachers_with_semester, function (index, semester) {

            // Make Subject Teachers View
            let subject_teachers_data = "";
            $.each(semester.subject_teachers, function (index, subjectData) {
                subject_teachers_data += '<tr><th scope="row">-></th><td>' + subjectData.subject_name + '</td><td>' + subjectData.teacher_name + '</td></tr>'
            });

            // Table View
            html += `<thead>
                        <tr>
                            <th scope="col"></th>
                            <th scope="col" class="text-center"><h3><u>` + semester.semester_name + `</u></h3></th>
                            <th></th>
                        </tr>
                    </thead>
                    <thead>
                        <tr>
                            <th scope="col"></th>
                            <th scope="col">` + window.trans["subject_name"] + `</th>
                            <th scope="col">` + window.trans["subject_teachers"] + `</th>
                        </tr>
                    </thead>
                    <tbody border="2">` + subject_teachers_data + `</tbody>`
        });
        html += '</table>';
        return html;
    }
}

function attendanceTypeFormatter(value) {
    if (value == 0) {
        return '<label class="badge badge-danger">' + window.trans["absent"] + '</label>';
    } else if (value == 1) {
        return '<label class="badge badge-info">' + window.trans["present"] + '</label>';
    } else {
        return '<label class="badge badge-success">' + window.trans["holiday"] + '</label>';
    }
}

function shiftStatusFormatter(value, row) {
    if (row.status == 1) {
        return "<span class='badge badge-success'>" + window.trans["Active"] + "</span>";
    } else {
        return "<span class='badge badge-danger'>" + window.trans["Inactive"] + "</span>";
    }
}

function activeStatusFormatter(value, row) {
    if (row.status == 1) {
        return "<span class='badge badge-success'>" + window.trans["Active"] + "</span>";
    } else {
        return "<span class='badge badge-danger'>" + window.trans["Inactive"] + "</span>";
    }
}

function schoolActiveStatusFormatter(value, row) {
    if (row.installed == 1) {
        return activeStatusFormatter(value, row);
    } else {
        return '<div class="bar-loader"> <span></span> <span></span> <span></span> <span></span> </div>';
    }
}

function verifyEmailStatusFormatter(value, row) {

    if (row.user.email_verified_at != null) {
        return "<span class='badge badge-success'>" + window.trans["verified"] + "</span>";
    } else {
        return "<span class='badge badge-danger'>" + window.trans["unverified"] + "</span>";
    }
}

function amountFormatter(value, row) {
    return formatMoneyJS(value);
}

function totalFormatter() {
    return window.trans['total'];
}

function totalAmountFormatter(data) {
    let field = this.field
    let amount = 0;
    data.map(function (row) {
        amount += parseFloat(row[field]);
    })
    return formatMoneyJS(amount);
}

function feesInstallmentFormatter(value, row) {
    let html;
    if (row.installments) {
        html = "<ol>";
        row.installments.forEach(function (data) {
            html += "<li>" + data.name + " (" + data.due_date + ")</li>";
        })
        html += "</ol>";
    }
    return html;
}

function totalFeesFormatter(value, row) {
    $('.total_fees_statistics').html(0);
    $('.total_compulsory_fees').html(0);
    $('.total_optional_fees').html(0);

    $('.total_fees_collected').html(0);
    $('.total_compulsory_fees_collected').html(0);
    $('.total_optional_fees_collected').html(0);

    $('.total_fees_pending').html(0);
    $('.total_compulsory_fees_pending').html(0);
    $('.total_optional_fees_pending').html(0);

    // Total Fees
    if (row.no.total_fees) {
        $('.total_fees_statistics').html(formatMoneyJS(row.no.total_fees));
    }

    if (row.no.total_compulsory_fees) {
        $('.total_compulsory_fees').html(formatMoneyJS(row.no.total_compulsory_fees));
    }

    if (row.no.total_optional_fees) {
        $('.total_optional_fees').html(formatMoneyJS(row.no.total_optional_fees));
    }
    // End Total Fees

    // Collected Fees
    if (row.no.total_fees_collected) {
        $('.total_fees_collected').html(formatMoneyJS(row.no.total_fees_collected));
    }

    if (row.no.total_compulsory_fees_collected) {
        $('.total_compulsory_fees_collected').html(formatMoneyJS(row.no.total_compulsory_fees_collected));
    }

    if (row.no.total_optional_fees_collected) {
        $('.total_optional_fees_collected').html(formatMoneyJS(row.no.total_optional_fees_collected));
    }

    // Total Pending Fees
    let total_pending_fees = (row.no.total_fees ? parseInt(row.no.total_fees) : 0) - (row.no.total_fees_collected ? parseInt(row.no.total_fees_collected) : 0);

    let total_compulsory_fees_pending = (row.no.total_compulsory_fees ? parseInt(row.no.total_compulsory_fees) : 0) - (row.no.total_compulsory_fees_collected ? parseInt(row.no.total_compulsory_fees_collected) : 0);

    let total_optional_fees_pending = (row.no.total_optional_fees ? parseInt(row.no.total_optional_fees) : 0) - (row.no.total_optional_fees_collected ? parseInt(row.no.total_optional_fees_collected) : 0);


    $('.total_fees_pending').html(formatMoneyJS(total_pending_fees));
    $('.total_compulsory_fees_pending').html(formatMoneyJS(total_compulsory_fees_pending));
    $('.total_optional_fees_pending').html(formatMoneyJS(total_optional_fees_pending));

    return row.no.no;
}


function schoolInquiryStatusFormatter(value, row) {
    let html;
    // 0 = Pending/In Review , 1 = Accepted , 2 = Rejected , 3 = Resubmitted
    if (row.status === 0) {
        html = "<span class='badge badge-warning'>" + window.trans['Pending'] + "</span>";
    } else if (row.status === 1) {
        html = "<span class='badge badge-success'>" + window.trans['Accepted'] + "</span>";
    } else if (row.status === 2) {
        html = "<span class='badge badge-danger'>" + window.trans['Rejected'] + "</span>";
    }
    return html;
}
function classSectionFormatter(value, row) {
    let html = '';
    if (row.is_linked === 1 || row.is_linked === '1') {
        // Smaller, rounded "Linked" badge
        html = value+'<span class="badge badge-info" style="font-size: 10px; padding: 2px 10px; border-radius: 999px; vertical-align: middle; margin-left: 5px;">Linked</span>';
    } else {
        html = value;
    }
    return html;
}

function classFormatter(value, row) {
    let list = value.split(",").map((item) => {
        if (item.length !== 0) {
            return "<li>" + item.trim() + "</li>";
        }
    }).join("");

    return "<ol>" + list + "</ol>";   
}

function marksSubmissionStatus(value, row) {
    let html = "<div>";

    if (!Array.isArray(row.classSectionWiseStatus)) {
        console.error("classSectionWiseStatus is not an array:", row.classSectionWiseStatus);
        return "<span style='color: red;'>Invalid data format</span>";
    }

    row.classSectionWiseStatus.forEach(function (sectionData) {
        html += "<div style='margin-bottom: 12px;'>";
        html += "<div style='margin-bottom:20px;'><strong style='margin-bottom: 12px;'>" + sectionData.class_section_name + "</strong></div>";
        html += "<ol style='padding-left: 20px;'>";

        sectionData.subject_wise_status.forEach(function (subjectData) {
            const badgeClass = subjectData.status === "Submitted"
                ? "badge-success"
                : "badge-danger";

            html += `<li style='margin-bottom: 8px;'> 
                         ${subjectData.subject} 
                         <span class='badge ${badgeClass}' style='padding: 4px 8px; margin-left: 8px;'>
                             ${subjectData.status}
                         </span>
                     </li>`;
        });

        html += "</ol>";
        html += "</div>";
    });

    html += "</div>";
    return html;
}

function classSectionSubmissionStatus(value, row) {
    let html = "<ol>";

    row.classSectionWiseStatus.forEach(function (data) {
        if (data.status == "Submitted") {
            html += "<li style='margin-bottom: 8px;'>" +
                data.class_section_name +
                " <span class='badge badge-success' style='padding: 4px 8px; margin-left: 8px;'>" +
                data.status +
                "</span></li>";
        } else {
            html += "<li style='margin-bottom: 8px;'>" +
                data.class_section_name +
                " <span class='badge badge-danger' style='padding: 4px 8px; margin-left: 8px;'>" +
                data.status +
                "</span></li>";
        }
    });

    html += "</ol>";

    return html;

}

// Format student name with avatar for elective subject assignment
function assignElectiveStudentNameFormatter(value, row) {
    let html = '';
    const imageUrl = row.user_image || (row.user && row.user.image) || '';
    const fullName = row.full_name || (row.user && row.user.full_name) || '';
    
    if (imageUrl && imageUrl !== '-' && imageUrl !== '') {
        const imgTag = '<img src="' + imageUrl + '" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';" />';
        const fallback = '<div class="rounded-circle bg-light text-primary d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px; font-weight: bold; display: none;">' + 
                        (fullName ? fullName.charAt(0).toUpperCase() : '?') + '</div>';
        html = '<div class="d-flex align-items-center">' + 
               imgTag + fallback +
               ' <div> <h6 class="mb-0">' + fullName + '</h6> </div> </div>';
    } else {
        const initial = fullName ? fullName.charAt(0).toUpperCase() : '?';
        html = '<div class="d-flex align-items-center">' + 
               '<div class="rounded-circle bg-light text-primary d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px; font-weight: bold;">' + 
               initial + '</div>' +
               ' <div> <h6 class="mb-0">' + fullName + '</h6> </div> </div>';
    }
    return html;
}

// Format status column with color-coded badges
function assignElectiveSubjectStatusFormatter(value, row) {
    const status = (row.status || '').toLowerCase();
    let badgeClass = 'badge-';
    let statusText = '';
    
    switch (status) {
        case 'complete':
            badgeClass += 'success';
            statusText = 'Complete';
            break;
        case 'incomplete':
            badgeClass += 'warning';
            statusText = 'Incomplete';
            break;
        case 'not_assigned':
            badgeClass += 'secondary';
            statusText = 'Not Assigned';
            break;
        default:
            badgeClass += 'secondary';
            statusText = 'Not Assigned';
    }
    return '<span class="badge ' + badgeClass + '">' + statusText + '</span>';
}

// Format elective subjects with better visualization
function assignElectiveSubjectsFormatter(value, row) {
    if (!row.elective_subjects || row.elective_subjects === '' || row.elective_subjects === '-') {
        return '<span class="text-muted">-</span>';
    }
    const subjects = row.elective_subjects.split(',').map(s => s.trim()).filter(s => s !== '');
    if (subjects.length === 0) return '<span class="text-muted">-</span>';
    let html = '<ol style="padding-left: 1.2em; margin: 0;">';
    subjects.forEach(subject => {
        html += '<li>' + subject + '</li>';
    });
    html += '</ol>';
    return html;
}

// Format action buttons for elective subject assignment
function assignElectiveSubjectActionFormatter(value, row) {
    const status = (row.status || '').toLowerCase();
    let buttonText = 'Assign';
    let buttonClass = 'btn-primary';
    
    if (status === 'complete' || status === 'incomplete') {
        buttonText = 'Update';
        buttonClass = 'btn-info';
    }
    
    return '<button type="button" class="btn btn-sm ' + buttonClass + ' assign-subject-btn" ' +
           'data-student-id="' + row.id + '" ' +
           'data-user-id="' + (row.user_id || '') + '" ' +
           'data-status="' + status + '">' + buttonText + '</button>';
}

// Format application status
function applicationStatusFormatter(value, row) {
    if (row.application_status == 1) {
        return '<span class="badge badge-success">' + window.trans['accepted'] + '</span>';
    } else {
        return '<span class="badge badge-danger">' + window.trans['rejected'] + '</span>';
    }
}

function transportationFeesFormatter(value, row) {
    let html;
    if (row.transportation_fees) {
        html = "<ol>";
        row.transportation_fees.forEach(function (data) {
            html += "<li>" + data.duration + " days - " + data.fee_amount + "</li>";
        })
        html += "</ol>";
    }
    return html;
}

function RouteNameFormatter(value, row) {
    if (row.route.shift) {
        return row.route.name + ' - ' + row.route.shift.name;
    } else {
        return row.route.name;
    }
}

function staffAttendanceUserFormatter(value, row) {
    if (row.user.full_name.full_name) {
        row.user = row.user.full_name;
    }
    return '<div class="d-flex align-items-center">' +
        '<a data-toggle="lightbox" href="' + row.user.image + '">' +
        '<img src="' + row.user.image + '" class="rounded-circle border"' +
        'style="width: 50px; height: 50px; object-fit: cover;" ' +
        'onerror="onErrorImage(event)"' +
        '>' +
        '</a>' +
        '<div class="ms-3 text-start">' +
        '<a href="#" class="text-dark text-decoration-none">' +
        '<h6 class="mb-0" style="max-width: 70%">' + row.user.full_name + '</h6>' +
        '</a>' +
        '</div>' +
        '</div>';
}

function staffAttendanceStatus(value, row) {

    let badge = '';
    let reasonText = '';

    let status = String(row.status ?? '');

    const reason = row.reason ?? (row.type?.reason ?? '');
    let leaveType = row.leave_type ?? null;

    const adminLeave = !!row.admin_leave;
    const attendanceLeave = !!row.attendance_leave;

    // ✅ Holiday Override
    const holidayDays = row.holiday_days
        ? row.holiday_days.split(',').map(d => d.trim().toLowerCase())
        : [];

    const dayName = row.day_name ? row.day_name.trim().toLowerCase() : '';

    if (holidayDays.includes(dayName)) {
        status = '3';
    }
    if (row.holiday) {
        status = '3';
    }
    if (leaveType == 'Full') {
        leaveType = window.trans['full_day'];
    } else if (leaveType == 'First Half') {
        leaveType = window.trans['first_half'];
    } else if (leaveType == 'Second Half') {
        leaveType = window.trans['second_half'];
    }

    // ✅ Status Badge
    switch (status) {
        case 'not marked':
            badge = `<span class="badge status-badge not-marked">${window.trans['not_marked']}</span>`;
            break;
        case '0':
            badge = `<span class="badge status-badge absent">${window.trans['absent']}</span>`;
            break;
        case '1':
            badge = `<span class="badge status-badge present">${window.trans['present']}</span>`;
            break;
        case '3':
            badge = `<span class="badge status-badge holiday">${window.trans['holiday']}</span>`;
            break;
        case '4':
            badge = `<span class="badge status-badge half-present">${window.trans['present_first_half_only']}</span>`;
            break;
        case '5':
            badge = `<span class="badge status-badge half-present">${window.trans['present_second_half_only']}</span>`;
            break;
        default:
            badge = `<span class="badge status-badge not-marked">${window.trans['not_marked']}</span>`;
    }

    // ✅ Leave Type Text
    if (adminLeave && leaveType && leaveType !== '') {
        reasonText += `<br><small class="text-primary">${leaveType} ${window.trans['leave']}</small>`;
    }
    if (adminLeave && leaveType && leaveType !== '' && leaveType == 'Full Day') {
        reasonText = ``;
        badge = `<span class="badge status-badge leave">${window.trans['full_day_leave']}</span>`;
    }

    if (reason && reason.trim() !== '' && reason !== 'undefined') {
        reasonText += `<br><small class="text-muted">${window.trans['reason']}: ${reason}</small>`;
    }

    return badge + reasonText;
}

function addStaffInputAttendance(value, row) {

    let disabled = '';

    const leaveType = row.leave_type ?? null;
    const adminLeave = !!row.admin_leave;
    const attendanceLeave = !!row.attendance_leave;

    // ✅ HOLIDAY disable
    const holidayDays = row.holiday_days
        ? row.holiday_days.split(',').map(d => d.trim().toLowerCase())
        : [];

    const dayName = row.day_name ? row.day_name.trim().toLowerCase() : '';

    if (holidayDays.includes(dayName)) {
        disabled = "disabled";
    }

    if (row.holiday) {
        disabled = "disabled";
    }

    // ✅ ADMIN full day leave → MUST disable
    if (adminLeave && leaveType === 'Full') {
        disabled = "disabled";
    }

    // ✅ ADMIN half-day leave → disable only the forbidden half
    if (adminLeave && (leaveType === 'First Half' || leaveType === 'Second Half')) {
        // Button stays enabled; actual half options are disabled inside modal
        disabled = '';
    }

    // ✅ ATTENDANCE-CREATED LEAVE → ALWAYS EDITABLE
    if (attendanceLeave) {
        disabled = '';
    }

    
    let status = '';
    if (row.type.status === 'Mark') {
        status = window.trans['mark'];
    } else if (row.type.status === 'Update') {
        status = window.trans['update'];
    }

    
    if (row.payroll_exists) {
        disabled = "disabled";
        status = window.trans['payroll_generated'];
    }

    // ✅ Build button
    return `
        <button type="button"
            class="btn btn-outline-secondary mark-btn btn-sm"
            data-staff-id="${row.type.id}"
            data-leave-type="${row.leave_type}"
            data-admin-leave="${row.admin_leave}"
            data-attendance-leave="${row.attendance_leave}"
            data-leave-detail-id="${row.leave_detail_id}"
            data-leave-id="${row.leave_id}"
            data-reason="${row.type.reason ?? ''}"
            data-attendance-type="${row.type.type}"
            data-name="${row.user?.full_name ?? ''}"
            data-attendance-id="${row.id}"
            data-date="${row.type.date}"
            data-toggle="modal"
            data-target="#markAttendanceModal"
            ${disabled}
        >${status}</button>
    `;
}

function tripReportUserFormatter(value, row) {
    return '<div class="d-flex align-items-center">' +
        '<a data-toggle="lightbox" href="' + row.created_by.image + '">' +
        '<img src="' + row.created_by.image + '" class="rounded-circle border"' +
        'style="width: 50px; height: 50px; object-fit: cover;" ' +
        'onerror="onErrorImage(event)"' +
        '>' +
        '</a>' +
        '<div class="ms-3 text-start">' +
        '<a href="#" class="text-dark text-decoration-none">' +
        '<h6 class="mb-0">' + row.created_by.full_name + '</h6>' +
        '<small class="mb-0">' + row.created_by.role + '</small>' +
        '</a>' +
        '</div>' +
        '</div>';
}