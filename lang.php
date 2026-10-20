<?php
/**
 * Language toggle — English / Urdu for the UI chrome ONLY.
 *
 * Scope (deliberate): navigation, page titles, KPI labels, common
 * buttons, status names and the login labels are translated. Data
 * values — company names, notes, amounts, dates — are never
 * translated, so figures stay identical in both languages.
 *
 * Switch with ?lang=ur or ?lang=en on any page; the choice is kept
 * in the session. Urdu mode renders the page RTL with a Nastaliq
 * font stack (see layout.php + assets/css/app.css).
 */
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ur'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

function appLang(): string
{
    return $_SESSION['lang'] ?? 'en';
}

function t(string $key): string
{
    static $dict = null;
    if ($dict === null) {
        $dict = [
            'en' => [
                'dashboard'        => 'Dashboard',
                'payment_grid'     => 'Payment Grid',
                'agreements'       => 'Agreements',
                'incentives'       => 'Incentives',
                'reminders'        => 'Reminders',
                'reports'          => 'Reports',
                'followups'        => 'Follow-ups',
                'followup_list'    => 'Follow-up List',
                'users'            => 'Users',
                'backup_restore'   => 'Backup & Restore',
                'audit_log'        => 'Audit Log',
                'sign_out'         => 'Sign out',
                'sign_in'          => 'Sign In',
                'username'         => 'Username',
                'password'         => 'Password',
                'total_expected'   => 'Total Expected',
                'total_received'   => 'Total Received',
                'total_overdue'    => 'Total Overdue',
                'awaited_amounts'  => 'Awaited Amounts',
                'expected'         => 'Expected',
                'received'         => 'Received',
                'paid'             => 'Paid',
                'balance'          => 'Balance',
                'overdue_balance'  => 'Overdue Balance',
                'partial'          => 'Partial',
                'due'              => 'Due',
                'overdue'          => 'Overdue',
                'awaited'          => 'Awaited',
                'pending'          => 'Pending',
                'out'              => 'Out of Period',
                'nobill'           => 'No Bill',
                'add'              => 'Add',
                'edit'             => 'Edit',
                'delete'           => 'Delete',
                'save'             => 'Save',
                'save_changes'     => 'Save Changes',
                'cancel'           => 'Cancel',
                'search'           => 'Search',
                'filter'           => 'Filter',
                'print'            => 'Print',
                'company'          => 'Company',
                'status'           => 'Status',
                'amount'           => 'Amount',
                'date'             => 'Date',
                'actions'          => 'Actions',
                'add_agreement'    => 'Add Agreement',
                'add_incentive'    => 'Add Incentive',
                'record_payment'   => 'Record Payment',
                'supplier_statement' => 'Supplier Statement',
                'mark_sent'        => 'Mark Sent',
                'whatsapp'         => 'WhatsApp',
                'email'            => 'Email',
                'view_all'         => 'View all',
                'download_csv'     => 'Download CSV',
                'ledger'           => 'Ledger',
                'followup_needed'  => 'Follow-up Needed',
                'open_calling_list' => 'Open calling list',
            ],
            'ur' => [
                'dashboard'        => 'ڈیش بورڈ',
                'payment_grid'     => 'ادائیگی گرڈ',
                'agreements'       => 'معاہدے',
                'incentives'       => 'مراعات',
                'reminders'        => 'یاددہانیاں',
                'reports'          => 'رپورٹس',
                'followups'        => 'فالو اپ',
                'followup_list'    => 'فالو اپ فہرست',
                'users'            => 'صارفین',
                'backup_restore'   => 'بیک اپ اور بحالی',
                'audit_log'        => 'آڈٹ لاگ',
                'sign_out'         => 'لاگ آؤٹ',
                'sign_in'          => 'سائن ان',
                'username'         => 'صارف نام',
                'password'         => 'پاس ورڈ',
                'total_expected'   => 'کل متوقع رقم',
                'total_received'   => 'کل موصول رقم',
                'total_overdue'    => 'کل زائد المیعاد رقم',
                'awaited_amounts'  => 'منتظر رقوم',
                'expected'         => 'متوقع',
                'received'         => 'موصول',
                'paid'             => 'ادا شدہ',
                'balance'          => 'بیلنس',
                'overdue_balance'  => 'زائد المیعاد بیلنس',
                'partial'          => 'جزوی',
                'due'              => 'واجب الادا',
                'overdue'          => 'زائد المیعاد',
                'awaited'          => 'منتظر',
                'pending'          => 'زیر التوا',
                'out'              => 'مدت سے باہر',
                'nobill'           => 'کوئی بل نہیں',
                'add'              => 'شامل کریں',
                'edit'             => 'ترمیم کریں',
                'delete'           => 'حذف کریں',
                'save'             => 'محفوظ کریں',
                'save_changes'     => 'تبدیلیاں محفوظ کریں',
                'cancel'           => 'منسوخ کریں',
                'search'           => 'تلاش کریں',
                'filter'           => 'فلٹر',
                'print'            => 'پرنٹ کریں',
                'company'          => 'کمپنی',
                'status'           => 'حالت',
                'amount'           => 'رقم',
                'date'             => 'تاریخ',
                'actions'          => 'کارروائیاں',
                'add_agreement'    => 'نیا معاہدہ شامل کریں',
                'add_incentive'    => 'نئی مراعت شامل کریں',
                'record_payment'   => 'ادائیگی درج کریں',
                'supplier_statement' => 'سپلائر اسٹیٹمنٹ',
                'mark_sent'        => 'بھیج دیا نشان لگائیں',
                'whatsapp'         => 'واٹس ایپ',
                'email'            => 'ای میل',
                'view_all'         => 'تمام دیکھیں',
                'download_csv'     => 'CSV ڈاؤن لوڈ کریں',
                'ledger'           => 'کھاتہ',
                'followup_needed'  => 'پیروی درکار ہے',
                'open_calling_list' => 'کالنگ فہرست کھولیں',
            ],
        ];
    }
    $lang = appLang();
    return $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
}

/** URL of the current page with the language switched; other GET params kept. */
function langUrl(string $lang): string
{
    $params = $_GET;
    $params['lang'] = $lang;
    $page = basename($_SERVER['PHP_SELF'] ?? 'index.php');
    return $page . '?' . http_build_query($params);
}
