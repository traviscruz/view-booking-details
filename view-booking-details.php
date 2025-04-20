<?php
// Start session for flash messages and user authentication
session_start();

// timezone
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id']) || 
    ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 2 && $_SESSION['role_id'] != 5)) {
    $_SESSION['error'] = "You have no permission to access this page.";
    header("Location: login.php");
    exit();
}

// Include database connection
require 'config/database.php';

// Check if booking ID is provided
if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No booking specified.";
    header("Location: bookings.php");
    exit();
}

$booking_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Process status update if form was submitted
if (isset($_POST['update_status'])) {
    $new_status_id = $_POST['new_status'];
    $early_completion = isset($_POST['early_completion']) ? true : false;
    
    try {
        // Get current booking details to validate dates and status
        $booking_sql = "SELECT check_in_date, check_in_time, check_out_date, check_out_time, booking_status_id 
                        FROM Bookings 
                        WHERE booking_id = :booking_id";
        $booking_stmt = $conn->prepare($booking_sql);
        $booking_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
        $booking_stmt->execute();
        $booking_data = $booking_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get status name for validation
        $status_sql = "SELECT status_name FROM Status WHERE status_id = :status_id";
        $status_stmt = $conn->prepare($status_sql);
        $status_stmt->bindParam(':status_id', $new_status_id, PDO::PARAM_INT);
        $status_stmt->execute();
        $status_data = $status_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get current status name
        $current_status_sql = "SELECT status_name FROM Status WHERE status_id = :status_id";
        $current_status_stmt = $conn->prepare($current_status_sql);
        $current_status_stmt->bindParam(':status_id', $booking_data['booking_status_id'], PDO::PARAM_INT);
        $current_status_stmt->execute();
        $current_status_data = $current_status_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get current date and time (in Manila time)
        $current_datetime = new DateTime('now');
        $check_in_datetime = new DateTime($booking_data['check_in_date'] . ' ' . $booking_data['check_in_time']);
        $check_out_datetime = new DateTime($booking_data['check_out_date'] . ' ' . $booking_data['check_out_time']);

        echo '<script>';
        echo 'console.log("Current datetime: ' . $current_datetime->format('Y-m-d H:i:s') . '");';
        echo 'console.log("Check-in datetime: ' . $check_in_datetime->format('Y-m-d H:i:s') . '");';
        echo 'console.log("Check-out datetime: ' . $check_out_datetime->format('Y-m-d H:i:s') . '");';
        echo '</script>';
        
        // Validate based on status
        $update_allowed = false;
        
        // Check if trying to set status to Cancelled
        if ($status_data['status_name'] == 'Cancelled') {
            // Only allow cancellation if current status is Confirmed
            if ($current_status_data['status_name'] == 'Confirmed') {
                $update_allowed = true;
            } else {
                $_SESSION['error'] = "Booking can only be cancelled if current status is 'Confirmed'.";
            }
        } 
        else if ($status_data['status_name'] == 'Checked-in') {
            // For check-in, current time must be equal or later than check-in time
            if ($current_datetime >= $check_in_datetime) {
                $update_allowed = true;
            } else {
                $_SESSION['error'] = "Cannot check in before the scheduled check-in time.";
            }
        } elseif ($status_data['status_name'] == 'Completed') {
            // For completing a booking early (from checked-in)
            if ($current_status_data['status_name'] == 'Checked-in') {
                // Allow early completion if confirmation was given
                if ($early_completion) {
                    $update_allowed = true;
                    
                    // Update the check-out date to today
                    $today = $current_datetime->format('Y-m-d');
                    $update_checkout_sql = "UPDATE Bookings 
                                            SET check_out_date = :today 
                                            WHERE booking_id = :booking_id";
                    $update_checkout_stmt = $conn->prepare($update_checkout_sql);
                    $update_checkout_stmt->bindParam(':today', $today);
                    $update_checkout_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
                    $update_checkout_stmt->execute();
                } 
                // Regular completion - only if current time is equal or later than check-out time
                else if ($current_datetime >= $check_out_datetime) {
                    $update_allowed = true;
                } else {
                    $_SESSION['error'] = "Cannot mark as completed before the scheduled check-out time without confirmation.";
                }
            } else {
                $_SESSION['error'] = "Booking can only be completed if current status is 'Checked-in'.";
            }
        } else {
            // For other statuses, allow the update
            $update_allowed = true;
        }
        
        // Update the booking status if allowed
        if ($update_allowed) {
            $update_sql = "UPDATE Bookings SET booking_status_id = :status_id WHERE booking_id = :booking_id";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bindParam(':status_id', $new_status_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
            $update_stmt->execute();
            
            // Add audit trail entry for the status update
            $userId = $_SESSION['user_id'];
            $actionPerformed = "Updated Booking #$booking_id status to " . $status_data['status_name'];
            if ($early_completion && $status_data['status_name'] == 'Completed') {
                $actionPerformed .= " (Early completion - check-out date updated to " . $current_datetime->format('Y-m-d') . ")";
            }
            $tableAffected = "Bookings";
            $auditSql = "INSERT INTO AuditTrail (user_id, action_performed, table_affected)
                        VALUES (:userId, :actionPerformed, :tableAffected)";
            $auditStmt = $conn->prepare($auditSql);
            $auditStmt->bindParam(':userId', $userId);
            $auditStmt->bindParam(':actionPerformed', $actionPerformed);
            $auditStmt->bindParam(':tableAffected', $tableAffected);
            $auditStmt->execute();
            
            $_SESSION['success'] = "Booking status updated successfully.";
            header("Location: view-booking-details.php?id=$booking_id");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating booking status: " . $e->getMessage();
    }
}

// Get detailed booking information
try {
    // First check if this booking belongs to the current user
    $verify_sql = "SELECT guest_id FROM Bookings WHERE booking_id = :booking_id";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $verify_stmt->execute();
    $booking_owner = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get booking details with cabin info and status
    $sql = "SELECT b.*, c.cabin_name, s.status_name 
            FROM Bookings b
            JOIN Cabins c ON b.cabin_id = c.cabin_id
            JOIN Status s ON b.booking_status_id = s.status_id
            WHERE b.booking_id = :booking_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $stmt->execute();
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $_SESSION['error'] = "Booking not found.";
        header("Location: bookings.php");
        exit();
    }

    // Get user information (owner of the booking)
    $user_sql = "SELECT first_name, last_name FROM User WHERE user_id = :guest_id";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bindParam(':guest_id', $booking['guest_id'], PDO::PARAM_INT);
    $user_stmt->execute();
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payment information - Updated to include transaction_reference
    $payment_sql = "SELECT p.*, pm.type_name as payment_mode, pc.category_name as payment_category, 
                    s.status_name as payment_status, p.payment_date, p.transaction_reference
                    FROM Payment p
                    JOIN PaymentMode pm ON p.payment_mode_id = pm.payment_mode_id
                    JOIN PaymentCategory pc ON p.payment_category_id = pc.payment_category_id
                    JOIN Status s ON p.payment_status_id = s.status_id
                    WHERE p.booking_id = :booking_id
                    ORDER BY p.payment_date ASC";
    
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $payment_stmt->execute();
    $payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available statuses for updating
    $statuses_sql = "SELECT status_id, status_name FROM Status 
                    WHERE status_name IN ('Checked-in', 'Completed', 'Cancelled')";
    $statuses_stmt = $conn->prepare($statuses_sql);
    $statuses_stmt->execute();
    $available_statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);



    // Get payment information - Updated to include transaction_reference
    $payment_sql = "SELECT p.*, pm.type_name as payment_mode, pc.category_name as payment_category, 
        s.status_name as payment_status, p.payment_date, p.transaction_reference,
        d.discount_name, d.percentage_off
        FROM Payment p
        JOIN PaymentMode pm ON p.payment_mode_id = pm.payment_mode_id
        JOIN PaymentCategory pc ON p.payment_category_id = pc.payment_category_id
        JOIN Status s ON p.payment_status_id = s.status_id
        LEFT JOIN Discount d ON p.discount_id = d.discount_id
        WHERE p.booking_id = :booking_id
        ORDER BY p.payment_date ASC";
    
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $payment_stmt->execute();
    $payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get food orders information
    $food_orders_sql = "SELECT fo.order_id, fo.total_amount, fo.order_date
    FROM FoodOrders fo
    WHERE fo.booking_id = :booking_id
    ORDER BY fo.order_date ASC";

    $food_orders_stmt = $conn->prepare($food_orders_sql);
    $food_orders_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $food_orders_stmt->execute();
    $food_orders = $food_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare arrays to store order details and payments
    $order_details = [];
    $food_payments = [];

    // For each food order, get its details and payment information
    foreach ($food_orders as $order) {
    // Get order details
    $order_details_sql = "SELECT od.order_item_id, od.quantity, od.subtotal, 
        f.food_name, s.status_name as order_status
        FROM OrderDetails od
        JOIN FoodItems f ON od.food_id = f.food_id
        JOIN Status s ON od.order_status_id = s.status_id
        WHERE od.order_id = :order_id
        AND s.status_name = 'Served'";
        
    $order_details_stmt = $conn->prepare($order_details_sql);
    $order_details_stmt->bindParam(':order_id', $order['order_id'], PDO::PARAM_INT);
    $order_details_stmt->execute();
    $details = $order_details_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($details)) {
        $order_details[$order['order_id']] = $details;
    }

    // Get payment for this order
    $food_payment_sql = "SELECT p.*, pm.type_name as payment_mode, 
        s.status_name as payment_status, p.payment_date, p.transaction_reference,
        d.discount_name, d.percentage_off, pc.category_name
        FROM Payment p
        JOIN PaymentMode pm ON p.payment_mode_id = pm.payment_mode_id
        JOIN Status s ON p.payment_status_id = s.status_id
        JOIN PaymentCategory pc ON p.payment_category_id = pc.payment_category_id
        LEFT JOIN Discount d ON p.discount_id = d.discount_id
        WHERE p.booking_id = :booking_id
        AND p.order_id = :order_id
        ORDER BY p.payment_date ASC";

        $food_payment_stmt = $conn->prepare($food_payment_sql);
        $food_payment_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
        $food_payment_stmt->bindParam(':order_id', $order['order_id'], PDO::PARAM_INT);
        $food_payment_stmt->execute();
        $payment = $food_payment_stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
    $food_payments[$order['order_id']] = $payment;
    }
    }

    // Get booked activities information
    $activities_sql = "SELECT ba.booked_activity_id, a.activity_name, ba.booking_date, ba.booking_time, s.status_name,
    bap.overall_price, bap.adults, bap.teens, bap.kids, bap.toddlers,
    bap.adult_price, bap.teen_price, bap.kid_price, bap.toddler_price
    FROM BookedActivities ba
    JOIN Activities a ON ba.activity_id = a.activity_id
    JOIN Status s ON ba.status_id = s.status_id
    JOIN BookedActivityParticipants bap ON ba.booked_activity_id = bap.booked_activity_id
    WHERE ba.booking_id = :booking_id
    ORDER BY ba.booking_date, ba.booking_time ASC";

    $activities_stmt = $conn->prepare($activities_sql);
    $activities_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $activities_stmt->execute();
    $booked_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare array to store activity payments
    $activity_payments = [];

    // For each booked activity, get its payment information
    foreach ($booked_activities as $activity) {
    // Get payment for this activity
    $activity_payment_sql = "SELECT p.*, pm.type_name as payment_mode, 
        s.status_name as payment_status, p.payment_date, p.transaction_reference,
        d.discount_name, d.percentage_off, pc.category_name
        FROM Payment p
        JOIN PaymentMode pm ON p.payment_mode_id = pm.payment_mode_id
        JOIN Status s ON p.payment_status_id = s.status_id
        JOIN PaymentCategory pc ON p.payment_category_id = pc.payment_category_id
        LEFT JOIN Discount d ON p.discount_id = d.discount_id
        WHERE p.booking_id = :booking_id
        AND p.booked_activity_id = :booked_activity_id
        ORDER BY p.payment_date ASC";

    $activity_payment_stmt = $conn->prepare($activity_payment_sql);
    $activity_payment_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $activity_payment_stmt->bindParam(':booked_activity_id', $activity['booked_activity_id'], PDO::PARAM_INT);
    $activity_payment_stmt->execute();
    $payment = $activity_payment_stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
    $activity_payments[$activity['booked_activity_id']] = $payment;
    }
    }

    // Get all additional booking fees for this booking
$additional_fees_sql = "SELECT * FROM AdditionalBookingFees WHERE booking_id = :booking_id ORDER BY created_at DESC";
$additional_fees_stmt = $conn->prepare($additional_fees_sql);
$additional_fees_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
$additional_fees_stmt->execute();
$additional_booking_fees = $additional_fees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize arrays to store additional fee details and payments
$additional_fee_details = [];
$additional_fee_payments = [];

// Get details for each additional booking fee
foreach ($additional_booking_fees as $booking_fee) {
    $fee_details_sql = "SELECT afd.*, af.fee_name, af.fee_price, s.status_name 
                        FROM AdditionalFeeDetails afd
                        LEFT JOIN AdditionalFees af ON afd.additional_fee_id = af.additional_fee_id
                        JOIN Status s ON afd.status_id = s.status_id
                        WHERE afd.additional_booking_fee_id = :additional_booking_fee_id";
    
    $fee_details_stmt = $conn->prepare($fee_details_sql);
    $fee_details_stmt->bindParam(':additional_booking_fee_id', $booking_fee['additional_booking_fee_id'], PDO::PARAM_INT);
    $fee_details_stmt->execute();
    $fee_items = $fee_details_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($fee_items)) {
        $additional_fee_details[$booking_fee['additional_booking_fee_id']] = $fee_items;
    }
    
    // Get payment for this additional fee
    $additional_fee_payment_sql = "SELECT p.*, pm.type_name as payment_mode,
                                   s.status_name as payment_status, p.payment_date, p.transaction_reference,
                                   d.discount_name, d.percentage_off, pc.category_name
                                   FROM Payment p
                                   JOIN PaymentMode pm ON p.payment_mode_id = pm.payment_mode_id
                                   JOIN Status s ON p.payment_status_id = s.status_id
                                   JOIN PaymentCategory pc ON p.payment_category_id = pc.payment_category_id
                                   LEFT JOIN Discount d ON p.discount_id = d.discount_id
                                   WHERE p.booking_id = :booking_id
                                   AND p.additional_booking_fee_id = :additional_booking_fee_id
                                   ORDER BY p.payment_date ASC";
    
    $additional_fee_payment_stmt = $conn->prepare($additional_fee_payment_sql);
    $additional_fee_payment_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
    $additional_fee_payment_stmt->bindParam(':additional_booking_fee_id', $booking_fee['additional_booking_fee_id'], PDO::PARAM_INT);
    $additional_fee_payment_stmt->execute();
    $payment = $additional_fee_payment_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payment) {
        $additional_fee_payments[$booking_fee['additional_booking_fee_id']] = $payment;
    }
}
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching booking details: " . $e->getMessage();
    header("Location: bookings.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <!-- <link rel="stylesheet" href="assets/css/guest-services.css"> -->
    <link rel="stylesheet" href="assets/css/my-bookings copy.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&display=swap" rel="stylesheet">
    <title>Booking Details</title>
</head>
<style>
        .confirmation-icon {
                text-align: center;
                margin-bottom: 20px;
            }
        
        .confirmation-icon i {
            font-size: 48px;
            color: #f39c12; /* Warning yellow color */
        }
        
        /* For error/warning modal */
        .warning-icon i {
            color: #e74c3c; /* Red for warnings/danger */
        }
        .modal-content {
            margin-bottom: 20px;
        }

        .modal-content p {
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .modal-content ul {
            margin-left: 20px;
            margin-bottom: 15px;
        }

        .modal-content ul li {
            margin-bottom: 5px;
        }

        .modal-content strong {
            color: #e74c3c;
        }
        .discount-info {
            display: block;
            font-size: 0.9em;
            color: #2ecc71;
            margin-top: 3px;
            font-weight: 500;
        }

        /* Food order and activitydiscount style */
        .food-discount-info {
            display: block;
            font-size: 0.75em;
            color: #2ecc71;
            margin-top: 3px;
            font-weight: 500;
        }

        .activity-discount-info {
            display: block;
            font-size: 0.75em;
            color: #2ecc71;
            margin-top: 3px;
            font-weight: 500;
        }
                /* Additional styles for payment information */
        .payment-section {
            margin-top: 20px;
            padding: 20px;
            background-color: #f9f9f9;
            /* border-radius: 8px;
            border-left: 4px solid #3498db; */
        }
        
        .payment-section h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1b3e1b;
            margin-bottom: 20px;
        }
        
        .payment-details {
            display: grid;
            gap: 15px;
        }
        
        .payment-info-group {
            display: flex;
            justify-content: space-between;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e0e0e0;
        }
        
        .payment-date {
            display: block;
            font-size: 0.8em;
            color: #7f8c8d;
            margin-top: 2px;
        }
        
        .transaction-reference {
            display: block;
            font-size: 0.9em;
            color: #34495e;
            margin-top: 2px;
            font-weight: 500;
        }
        
        .payment-status-paid {
            color: #27ae60;
            font-weight: bold;
        }
        
        .payment-status-partial {
            color: #e67e22;
            font-weight: bold;
        }
        
        .total-amount {
            font-size: 1.2em;
            font-weight: bold;
            margin-top: 10px;
            border-top: 2px solid #e0e0e0;
            padding-top: 10px;
            border-bottom: none;
        }
        
        .no-payment-info {
            text-align: center;
            padding: 20px;
            background-color: #f5f5f5;
            border-radius: 5px;
            color: #7f8c8d;
        }
        
        .booking-card.detailed {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        /* Status Update Form Styles */
        .status-update-section {
            margin-top: 20px;
            padding: 20px;
            background-color: #f0f7f0;
            border-radius: 8px;
            border-left: 4px solid #1b3e1b;
        }
        
        .status-update-section h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1b3e1b;
            margin-bottom: 20px;
        }
        
        .status-update-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .form-group label {
            font-weight: 500;
            color: #2c3e50;
        }
        
        .form-group select {
            padding: 10px;
            border: 1px solid #1B3E1B;
            border-radius: 4px;
            font-size: 16px;
        }
        
        .btn-update-status {
            background-color: #1b3e1b;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s ease;
            align-self: flex-start;
        }
        
        .btn-update-status:hover {
            background-color: #265c26;
        }
        
        /* Alert Styles */
        .alert-container {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-error {
            background-color: #ffecec;
            border-left: 4px solid #e74c3c;
            color: #c0392b;
        }
        
        .alert-success {
            background-color: #e7f9e7;
            border-left: 4px solid #27ae60;
            color: #1e8449;
        }
        
        .alert-container i {
            font-size: 1.2em;
        }
        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 500px;
            padding: 25px;
            position: relative;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #2c3e50;
        }
        
        .modal-close {
            font-size: 1.4rem;
            color: #7f8c8d;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .modal-close:hover {
            color: #34495e;
        }
        
        .modal .status-update-form {
            margin-top: 0;
        }
        
        .modal .form-group {
            margin-bottom: 20px;
        }
        
        .modal .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn-cancel {
            background-color:rgb(140, 140, 140);
            color: white;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-cancel:hover {
            background-color: #7f8c8d;
        }
        
        .btn-update-status {
            background-color: #1B3E1B;
            color: white;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-update-status:hover {
            background-color: #31572C;
        }
        
        /* Add button to open modal */
        .btn-change-status {
            background-color:transparent;
            color:rgb(255, 255, 255);
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 8px;

        }
        
        /* .btn-change-status:hover {
            background-color: #2980b9;
        } */
        
        .booking-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
<body>
    <?php include 'dashboard-menu.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>Booking Details - <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
            <a href="bookings.php" class="btn-new-booking"><i class="fas fa-arrow-left"></i> Back to Bookings</a>
        </div>
        
        <!-- Flash message display -->
        <?php                 
        if (isset($_SESSION['error'])) {                     
            echo '<div class="alert-container alert-error">
                <i class="fas fa-exclamation-circle"></i>' . $_SESSION['error'] . '</div>';
            unset($_SESSION['error']);
        } elseif (isset($_SESSION['success'])) {
            echo '<div class="alert-container alert-success">
                <i class="fas fa-check-circle"></i>' . $_SESSION['success'] . '</div>';
            unset($_SESSION['success']);
        }
        ?>
        
        <div class="booking-details-container">
            <div class="booking-card detailed">
                <div class="booking-header">
                    <div class="booking-title">
                        <h3><?php echo htmlspecialchars($booking['cabin_name']); ?></h3>
                        <?php if (strtolower($booking['status_name']) !== 'cancelled'): ?>
                            <span class="status-badge <?php echo strtolower($booking['status_name']); ?>">
                                <?php echo htmlspecialchars($booking['status_name']); ?>
                                <button class="btn-change-status" onclick="openStatusModal()">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </span>
                        <?php else: ?>
                        <?php endif; ?>
                    </div>
                    <div class="booking-id">Booking #<?php echo $booking['booking_id']; ?></div>
                </div>
                
                <div class="booking-details">
                    <?php
                    // Determine tour type
                    $check_in_date = new DateTime($booking['check_in_date']);
                    $check_out_date = new DateTime($booking['check_out_date']);
                    $interval = $check_in_date->diff($check_out_date);
                    $tour_type = ($interval->days > 0) ? "Overnight Tour" : "Day Tour";
                    
                    // Calculate total guests
                    $total_guests = $booking['num_adults'] + $booking['num_teens'] + $booking['num_kids'] + $booking['num_toddlers'];
                    ?>
                    
                    <div class="booking-info-group">
                        <div class="info-label"><i class="fas fa-ticket-alt"></i> Tour Type</div>
                        <div class="info-value"><?php echo $tour_type; ?></div>
                    </div>
                    
                    <div class="booking-info-group">
                        <div class="info-label"><i class="fas fa-calendar-check"></i> Check-in</div>
                        <div class="info-value">
                            <?php echo date('F j, Y', strtotime($booking['check_in_date'])); ?>
                            <span class="info-time"><?php echo date('g:i A', strtotime($booking['check_in_time'])); ?></span>
                        </div>
                    </div>
                    
                    <div class="booking-info-group">
                        <div class="info-label"><i class="fas fa-calendar-minus"></i> Check-out</div>
                        <div class="info-value">
                            <?php echo date('F j, Y', strtotime($booking['check_out_date'])); ?>
                            <span class="info-time"><?php echo date('g:i A', strtotime($booking['check_out_time'])); ?></span>
                        </div>
                    </div>
                    
                    <div class="booking-info-group">
                        <div class="info-label"><i class="fas fa-users"></i> Guests</div>
                        <div class="info-value">
                            <?php echo $total_guests; ?> total
                            <?php
                            // Show guest breakdown
                            $guest_types = [];
                            if ($booking['num_adults'] > 0) $guest_types[] = $booking['num_adults'] . ' adult' . ($booking['num_adults'] > 1 ? 's' : '');
                            if ($booking['num_teens'] > 0) $guest_types[] = $booking['num_teens'] . ' teen' . ($booking['num_teens'] > 1 ? 's' : '');
                            if ($booking['num_kids'] > 0) $guest_types[] = $booking['num_kids'] . ' kid' . ($booking['num_kids'] > 1 ? 's' : '');
                            if ($booking['num_toddlers'] > 0) $guest_types[] = $booking['num_toddlers'] . ' toddler' . ($booking['num_toddlers'] > 1 ? 's' : '');
                            
                            if (count($guest_types) > 1) {
                                echo ' <span class="guest-breakdown">(' . implode(', ', $guest_types) . ')</span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
<!-- Payment Information Section -->
<div class="payment-section">
    <h3>Cabin Payment</h3>
    
    <?php if (empty($payments)): ?>
        <div class="no-payment-info">
            <p>No payment information available for this booking.</p>
        </div>
    <?php else: ?>
        <div class="payment-details">
            <?php
            $total_paid = 0;
            $down_payment = null;
            $full_payment = null;
            $remaining_balance_payment = null;
            $remaining_balance = 0;
            
            foreach ($payments as $payment) {
                $total_paid += $payment['amount'];
                
                // Check payment category using the payment_category_id field
                if ($payment['payment_category_id'] == 1) {
                    $down_payment = $payment;
                } elseif ($payment['payment_category_id'] == 2) {
                    $full_payment = $payment;
                } elseif ($payment['payment_category_id'] == 3) {
                    $remaining_balance_payment = $payment;
                }
            }
            
            // Display payment info based on what we have
            if ($full_payment && !$down_payment) {
                // Only full payment exists
                ?>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-money-check"></i> Full Payment</div>
                    <div class="info-value">
                        ₱<?php echo number_format($full_payment['amount'], 2); ?>
                        <span class="payment-date">
                            paid on <?php echo date('F j, Y', strtotime($full_payment['payment_date'])); ?>
                            <?php if (!empty($full_payment['transaction_reference'])): ?>
                                <span class="transaction-reference">
                                    Ref: <?php echo htmlspecialchars($full_payment['transaction_reference']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($full_payment['discount_name'])): ?>
                                <span class="discount-info">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($full_payment['discount_name']); ?> 
                                    (<?php echo $full_payment['percentage_off']; ?>% off)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
                    <div class="info-value"><?php echo htmlspecialchars($full_payment['payment_mode']); ?></div>
                </div>
                <div class="payment-info-group payment-status-paid">
                    <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                    <div class="info-value">Fully Paid</div>
                </div>
            <?php
            } elseif ($down_payment && $remaining_balance_payment) {
                // Down payment and remaining balance payment exist
                ?>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-money-check"></i> Down Payment</div>
                    <div class="info-value">
                        ₱<?php echo number_format($down_payment['amount'], 2); ?>
                        <span class="payment-date">
                            paid on <?php echo date('F j, Y', strtotime($down_payment['payment_date'])); ?>
                            <?php if (!empty($down_payment['transaction_reference'])): ?>
                                <span class="transaction-reference">
                                    Ref: <?php echo htmlspecialchars($down_payment['transaction_reference']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($down_payment['discount_name'])): ?>
                                <span class="discount-info">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($down_payment['discount_name']); ?> 
                                    (<?php echo $down_payment['percentage_off']; ?>% off)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-money-check"></i> Remaining Balance</div>
                    <div class="info-value">
                        ₱<?php echo number_format($remaining_balance_payment['amount'], 2); ?>
                        <span class="payment-date">
                            paid on <?php echo date('F j, Y', strtotime($remaining_balance_payment['payment_date'])); ?>
                            <?php if (!empty($remaining_balance_payment['transaction_reference'])): ?>
                                <span class="transaction-reference">
                                    Ref: <?php echo htmlspecialchars($remaining_balance_payment['transaction_reference']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($remaining_balance_payment['discount_name'])): ?>
                                <span class="discount-info">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($remaining_balance_payment['discount_name']); ?> 
                                    (<?php echo $remaining_balance_payment['percentage_off']; ?>% off)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($down_payment['payment_mode']); ?> / 
                        <?php echo htmlspecialchars($remaining_balance_payment['payment_mode']); ?>
                    </div>
                </div>
                <div class="payment-info-group payment-status-paid">
                    <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                    <div class="info-value">Fully Paid</div>
                </div>
            <?php
            } elseif ($down_payment && !$remaining_balance_payment && !$full_payment) {
                // Only down payment exists
                $remaining_balance = $down_payment['amount']; // Assuming down payment is 50%
                ?>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-money-check"></i> Down Payment</div>
                    <div class="info-value">
                        ₱<?php echo number_format($down_payment['amount'], 2); ?>
                        <span class="payment-date">
                            paid on <?php echo date('F j, Y', strtotime($down_payment['payment_date'])); ?>
                            <?php if (!empty($down_payment['transaction_reference'])): ?>
                                <span class="transaction-reference">
                                    Ref: <?php echo htmlspecialchars($down_payment['transaction_reference']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($down_payment['discount_name'])): ?>
                                <span class="discount-info">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($down_payment['discount_name']); ?> 
                                    (<?php echo $down_payment['percentage_off']; ?>% off)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
                    <div class="info-value"><?php echo htmlspecialchars($down_payment['payment_mode']); ?></div>
                </div>
                <div class="payment-info-group payment-status-partial">
                    <div class="info-label"><i class="fas fa-exclamation-circle"></i> Remaining Balance</div>
                    <div class="info-value">₱<?php echo number_format($remaining_balance, 2); ?></div>
                </div>
            <?php
            } elseif ($down_payment && $full_payment) {
                // Both down payment and full payment exist
                ?>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-money-check"></i> Down Payment</div>
                    <div class="info-value">
                        ₱<?php echo number_format($down_payment['amount'], 2); ?>
                        <span class="payment-date">
                            paid on <?php echo date('F j, Y', strtotime($down_payment['payment_date'])); ?>
                            <?php if (!empty($down_payment['transaction_reference'])): ?>
                                <span class="transaction-reference">
                                    Ref: <?php echo htmlspecialchars($down_payment['transaction_reference']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($down_payment['discount_name'])): ?>
                                <span class="discount-info">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($down_payment['discount_name']); ?> 
                                    (<?php echo $down_payment['percentage_off']; ?>% off)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-money-check"></i> Complete Payment</div>
                    <div class="info-value">
                        ₱<?php echo number_format($full_payment['amount'], 2); ?>
                        <span class="payment-date">
                            paid on <?php echo date('F j, Y', strtotime($full_payment['payment_date'])); ?>
                            <?php if (!empty($full_payment['transaction_reference'])): ?>
                                <span class="transaction-reference">
                                    Ref: <?php echo htmlspecialchars($full_payment['transaction_reference']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($full_payment['discount_name'])): ?>
                                <span class="discount-info">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($full_payment['discount_name']); ?> 
                                    (<?php echo $full_payment['percentage_off']; ?>% off)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="payment-info-group">
                    <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($down_payment['payment_mode']); ?> / 
                        <?php echo htmlspecialchars($full_payment['payment_mode']); ?>
                    </div>
                </div>
                <div class="payment-info-group payment-status-paid">
                    <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                    <div class="info-value">Fully Paid</div>
                </div>
            <?php
            }
            ?>
            
            <div class="payment-info-group total-amount">
                <div class="info-label"><i class="fas fa-tag"></i> Total Amount</div>
                <div class="info-value">₱<?php echo number_format($total_paid + $remaining_balance, 2); ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>

                <!-- Food Orders Payment Section -->
                <?php if (!empty($food_orders) && !empty($order_details)): ?>
                        <div class="payment-section food-orders">
                            <h3>Food Orders Payments</h3>
                            
                            <?php foreach ($food_orders as $order): ?>
                                <?php if (isset($order_details[$order['order_id']])): ?>
                                    <div class="food-order-container">
                                        <div class="food-order-header">
                                            <h4>Order #<?php echo $order['order_id']; ?></h4>
                                            <span class="food-order-date">Ordered on <?php echo date('F j, Y', strtotime($order['order_date'])); ?></span>
                                        </div>
                                        
                                        <div class="food-order-items">
                                            <h5>Items</h5>
                                            <table class="food-items-table">
                                                <thead>
                                                    <tr>
                                                        <th>Item</th>
                                                        <th>Quantity</th>
                                                        <th>Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($order_details[$order['order_id']] as $item): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($item['food_name']); ?></td>
                                                            <td><?php echo $item['quantity']; ?></td>
                                                            <td>₱<?php echo number_format($item['subtotal'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr>
                                                        <td colspan="2" class="total-label">Total</td>
                                                        <td class="total-amount">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                        
            <div class="food-order-payment">
                <h5>Payment Details</h5>
                <?php if (isset($food_payments[$order['order_id']])): ?>
    <?php $payment = $food_payments[$order['order_id']]; ?>
    <div class="payment-info-group">
        <div class="info-label"><i class="fas fa-money-check"></i> Amount Paid</div>
        <div class="info-value">
            ₱<?php echo number_format($payment['amount'], 2); ?>
            <span class="payment-date">
                paid on <?php echo date('F j, Y', strtotime($payment['payment_date'])); ?>
                <?php if (!empty($payment['transaction_reference'])): ?>
                    <span class="transaction-reference">
                        Ref: <?php echo htmlspecialchars($payment['transaction_reference']); ?>
                    </span>
                <?php endif; ?>
            </span>
            <?php if (!empty($payment['discount_name'])): ?>
                <span class="food-discount-info">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($payment['discount_name']); ?> 
                    (<?php echo $payment['percentage_off']; ?>% off)
                </span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="payment-info-group">
        <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
        <div class="info-value"><?php echo htmlspecialchars($payment['payment_mode']); ?></div>
    </div>
    
    <?php if (!empty($payment['discount_name'])): ?>
        <div class="payment-info-group payment-status-paid">
            <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
            <div class="info-value">Fully Paid with Discount</div>
        </div>
    <?php else: ?>
        <?php 
        $remaining = $order['total_amount'] - $payment['amount'];
        if ($remaining <= 0): 
        ?>
            <div class="payment-info-group payment-status-paid">
                <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                <div class="info-value">Fully Paid</div>
            </div>
        <?php else: ?>
            <div class="payment-info-group payment-status-partial">
                <div class="info-label"><i class="fas fa-exclamation-circle"></i> Remaining Balance</div>
                <div class="info-value">₱<?php echo number_format($remaining, 2); ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
                    <div class="payment-info-group payment-status-partial">
                        <div class="info-label"><i class="fas fa-exclamation-circle"></i> Payment Status</div>
                        <div class="info-value">Unpaid</div>
                    </div>
                    <div class="payment-info-group payment-status-partial">
                        <div class="info-label"><i class="fas fa-exclamation-circle"></i> Balance Due</div>
                        <div class="info-value">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                    </div>
                <?php endif; ?>
            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Activities Payment Section -->
            <?php if (!empty($booked_activities)): ?>
            <div class="payment-section food-orders">
                <h3>Activity Bookings Payments</h3>
                
                <?php foreach ($booked_activities as $activity): ?>
                    <div class="food-order-container">
                        <div class="food-order-header">
                            <h4><?php echo htmlspecialchars($activity['activity_name']); ?></h4>
                            <span class="food-order-date">
                                Scheduled for <?php echo date('F j, Y', strtotime($activity['booking_date'])); ?> 
                                at <?php echo date('g:i A', strtotime($activity['booking_time'])); ?>
                            </span>
                        </div>
                        
                        <div class="food-order-items">
                        <h5>Participants</h5>
                        <table class="food-items-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Count</th>
                                    <th>Price per Person</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($activity['adult_price'] == 0 && $activity['teen_price'] == 0 && 
                                        $activity['kid_price'] == 0 && $activity['toddler_price'] == 0): ?>
                                    <tr>
                                        <td colspan="3">Group Price (All Participants)</td>
                                        <td>₱<?php echo number_format($activity['overall_price'], 2); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php if ($activity['adults'] > 0): ?>
                                    <tr>
                                        <td>Adults</td>
                                        <td><?php echo $activity['adults']; ?></td>
                                        <td>₱<?php echo number_format($activity['adult_price'], 2); ?></td>
                                        <td>₱<?php echo number_format($activity['adults'] * $activity['adult_price'], 2); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($activity['teens']) && $activity['teens'] > 0): ?>
                                    <tr>
                                        <td>Teens</td>
                                        <td><?php echo $activity['teens']; ?></td>
                                        <td>₱<?php echo number_format($activity['teen_price'], 2); ?></td>
                                        <td>₱<?php echo number_format($activity['teens'] * $activity['teen_price'], 2); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($activity['kids']) && $activity['kids'] > 0): ?>
                                    <tr>
                                        <td>Kids</td>
                                        <td><?php echo $activity['kids']; ?></td>
                                        <td>₱<?php echo number_format($activity['kid_price'], 2); ?></td>
                                        <td>₱<?php echo number_format($activity['kids'] * $activity['kid_price'], 2); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($activity['toddlers']) && $activity['toddlers'] > 0): ?>
                                    <tr>
                                        <td>Toddlers</td>
                                        <td><?php echo $activity['toddlers']; ?></td>
                                        <td>₱<?php echo number_format($activity['toddler_price'], 2); ?></td>
                                        <td>₱<?php echo number_format($activity['toddlers'] * $activity['toddler_price'], 2); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="total-label">Total</td>
                                    <td class="total-amount">₱<?php echo number_format($activity['overall_price'], 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                        
                        <div class="food-order-payment">
                            <h5>Payment Details</h5>
                            <?php if (isset($activity_payments[$activity['booked_activity_id']])): ?>
    <?php $payment = $activity_payments[$activity['booked_activity_id']]; ?>
    <div class="payment-info-group">
        <div class="info-label"><i class="fas fa-money-check"></i> Amount Paid</div>
        <div class="info-value">
            ₱<?php echo number_format($payment['amount'], 2); ?>
            <span class="payment-date">
                paid on <?php echo date('F j, Y', strtotime($payment['payment_date'])); ?>
                <?php if (!empty($payment['transaction_reference'])): ?>
                    <span class="transaction-reference">
                        Ref: <?php echo htmlspecialchars($payment['transaction_reference']); ?>
                    </span>
                <?php endif; ?>
            </span>
            <?php if (!empty($payment['discount_name'])): ?>
                <span class="activity-discount-info">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($payment['discount_name']); ?> 
                    (<?php echo $payment['percentage_off']; ?>% off)
                </span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="payment-info-group">
        <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
        <div class="info-value"><?php echo htmlspecialchars($payment['payment_mode']); ?></div>
    </div>
    
    <?php if (!empty($payment['discount_name'])): ?>
        <div class="payment-info-group payment-status-paid">
            <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
            <div class="info-value">Fully Paid with Discount</div>
        </div>
    <?php else: ?>
        <?php 
        $remaining = $activity['overall_price'] - $payment['amount'];
        if ($remaining <= 0): 
        ?>
            <div class="payment-info-group payment-status-paid">
                <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                <div class="info-value">Fully Paid</div>
            </div>
        <?php else: ?>
            <div class="payment-info-group payment-status-partial">
                <div class="info-label"><i class="fas fa-exclamation-circle"></i> Remaining Balance</div>
                <div class="info-value">₱<?php echo number_format($remaining, 2); ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
                                <div class="payment-info-group payment-status-partial">
                                    <div class="info-label"><i class="fas fa-exclamation-circle"></i> Payment Status</div>
                                    <div class="info-value">Unpaid</div>
                                </div>
                                <div class="payment-info-group payment-status-partial">
                                    <div class="info-label"><i class="fas fa-exclamation-circle"></i> Balance Due</div>
                                    <div class="info-value">₱<?php echo number_format($activity['overall_price'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>



            
            <!-- Additional Fees Payment Section -->
<?php if (!empty($additional_booking_fees) && !empty($additional_fee_details)): ?>
    <div class="payment-section food-orders">
        <h3>Additional Fees Payments</h3>
        
        <?php foreach ($additional_booking_fees as $booking_fee): ?>
            <?php if (isset($additional_fee_details[$booking_fee['additional_booking_fee_id']])): ?>
                <div class="food-order-container">
                    <div class="food-order-header">
                        <h4>Additional Fee #<?php echo $booking_fee['additional_booking_fee_id']; ?></h4>
                        <span class="food-order-date">Created on <?php echo date('F j, Y', strtotime($booking_fee['created_at'])); ?></span>
                    </div>
                    
                    <div class="food-order-items">
                        <h5>Items</h5>
                        <table class="food-items-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($additional_fee_details[$booking_fee['additional_booking_fee_id']] as $item): ?>
                                    <tr>
                                        <td>
                                            <?php if ($item['is_custom']): ?>
                                                <?php echo htmlspecialchars($item['custom_description']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($item['fee_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td>
                                            ₱<?php 
                                                if ($item['is_custom']) {
                                                    echo number_format($item['custom_price'] * $item['quantity'], 2);
                                                } else {
                                                    echo number_format($item['fee_price'] * $item['quantity'], 2);
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" class="total-label">Total</td>
                                    <td class="total-amount">₱<?php echo number_format($booking_fee['total_amount'], 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="food-order-payment">
                        <h5>Payment Details</h5>
                        <?php if (isset($additional_fee_payments[$booking_fee['additional_booking_fee_id']])): ?>
                            <?php $payment = $additional_fee_payments[$booking_fee['additional_booking_fee_id']]; ?>
                            <div class="payment-info-group">
                                <div class="info-label"><i class="fas fa-money-check"></i> Amount Paid</div>
                                <div class="info-value">
                                    ₱<?php echo number_format($payment['amount'], 2); ?>
                                    <span class="payment-date">
                                        paid on <?php echo date('F j, Y', strtotime($payment['payment_date'])); ?>
                                        <?php if (!empty($payment['transaction_reference'])): ?>
                                            <span class="transaction-reference">
                                                Ref: <?php echo htmlspecialchars($payment['transaction_reference']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($payment['discount_name'])): ?>
                                        <span class="food-discount-info">
                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($payment['discount_name']); ?> 
                                            (<?php echo $payment['percentage_off']; ?>% off)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="payment-info-group">
                                <div class="info-label"><i class="fas fa-receipt"></i> Payment Method</div>
                                <div class="info-value"><?php echo htmlspecialchars($payment['payment_mode']); ?></div>
                            </div>
                            
                            <?php if (!empty($payment['discount_name'])): ?>
                                <div class="payment-info-group payment-status-paid">
                                    <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                                    <div class="info-value">Fully Paid with Discount</div>
                                </div>
                            <?php else: ?>
                                <?php 
                                $remaining = $booking_fee['total_amount'] - $payment['amount'];
                                if ($remaining <= 0): 
                                ?>
                                    <div class="payment-info-group payment-status-paid">
                                        <div class="info-label"><i class="fas fa-check-circle"></i> Payment Status</div>
                                        <div class="info-value">Fully Paid</div>
                                    </div>
                                <?php else: ?>
                                    <div class="payment-info-group payment-status-partial">
                                        <div class="info-label"><i class="fas fa-exclamation-circle"></i> Remaining Balance</div>
                                        <div class="info-value">₱<?php echo number_format($remaining, 2); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="payment-info-group payment-status-partial">
                                <div class="info-label"><i class="fas fa-exclamation-circle"></i> Payment Status</div>
                                <div class="info-value">Unpaid</div>
                            </div>
                            <div class="payment-info-group payment-status-partial">
                                <div class="info-label"><i class="fas fa-exclamation-circle"></i> Balance Due</div>
                                <div class="info-value">₱<?php echo number_format($booking_fee['total_amount'], 2); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
            

        </div>
    </div>
</div>
    
    <!-- Status Update Modal -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Update Booking Status</h3>
                <button class="modal-close" onclick="closeStatusModal()">×</button>
            </div>
            
            <form method="POST" action="view-booking-details.php?id=<?php echo $booking_id; ?>" class="status-update-form">
                <div class="form-group">
                    <label for="new_status">New Status:</label>
                    <small>Kindly confirm your decision before changing the status.</small>
                    <select name="new_status" id="new_status" required>
                        <option value="">Select Status</option>
                        <?php foreach ($available_statuses as $status): ?>
                            <option value="<?php echo $status['status_id']; ?>"><?php echo htmlspecialchars($status['status_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn-update-status">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Completion Confirmation Modal -->
    <div class="modal-overlay" id="completionModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Confirm Early Completion</h3>
                <button class="modal-close" onclick="closeCompletionModal()">×</button>
            </div>
            
            <div class="modal-content">
                <p><strong>Warning:</strong> This action is irreversible!</p>
                <p>You are about to mark this booking as completed before the scheduled check-out date.</p>
                <p>This will:</p>
                <ul>
                    <li>Change the check-out date to today</li>
                    <li>Make the cabin available for future bookings</li>
                    <li>Finalize all booking records</li>
                </ul>
                <p>Are you sure you want to proceed?</p>
            </div>
            
            <form method="POST" action="view-booking-details.php?id=<?php echo $booking_id; ?>" id="completionForm">
                <input type="hidden" name="new_status" id="completion_status_id" value="">
                <input type="hidden" name="early_completion" value="1">
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeCompletionModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn-update-status">Confirm Completion</button>
                </div>
            </form>
        </div>
    </div>

    <!-- General Status Change Confirmation Modal -->
    <div class="modal-overlay" id="confirmationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Confirm Status Change</h3>
                <button class="modal-close" onclick="closeConfirmationModal()">×</button>
            </div>
            
            <div class="modal-content">
                <p>Are you sure you want to change the booking status to <strong id="confirm_status_name"></strong>?</p>
                <!-- <p>This action may affect:</p>
                <ul>
                    <li>Cabin availability</li>
                    <li>Payment records</li>
                    <li>Guest access</li>
                </ul> -->
            </div>
            
            <form method="POST" action="view-booking-details.php?id=<?php echo $booking_id; ?>" id="confirmForm">
                <input type="hidden" name="new_status" id="confirm_status_id" value="">
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn-update-status">Confirm Change</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check if sidebar is hidden and add appropriate class to main-content
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        
        if (sidebar && sidebar.classList.contains('hidden') && mainContent) {
            mainContent.classList.add('expanded');
        }
    });
    
    // Modal functions
    function openStatusModal() {
        document.getElementById('statusModal').style.display = 'flex';
    }
    
    function closeStatusModal() {
        document.getElementById('statusModal').style.display = 'none';
    }
    
    // Modal functions for completion confirmation
    function openCompletionModal(statusId) {
        document.getElementById('completion_status_id').value = statusId;
        document.getElementById('completionModal').style.display = 'flex';
    }

    function closeCompletionModal() {
        document.getElementById('completionModal').style.display = 'none';
    }

    // New confirmation modal functions
    function openConfirmationModal(statusId, statusName) {
        document.getElementById('confirm_status_id').value = statusId;
        document.getElementById('confirm_status_name').textContent = statusName;
        document.getElementById('confirmationModal').style.display = 'flex';
    }

    function closeConfirmationModal() {
        document.getElementById('confirmationModal').style.display = 'none';
    }

    // Modify status change handler to show confirmation modal for all status changes
    document.getElementById('new_status').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const statusId = this.value;
        const statusName = selectedOption.text;
        
        // Reset select to prevent confusion
        const currentSelectedIndex = this.selectedIndex;
        this.selectedIndex = 0;
        
        // For "Completed" status, check if early completion confirmation is needed
        if (statusName === 'Completed') {
            // Get the current date and scheduled checkout date
            const now = new Date();
            const scheduledCheckout = new Date('<?php echo $booking['check_out_date']; ?>');
            
            // If current date is before scheduled checkout, show early completion modal
            if (now < scheduledCheckout) {
                closeStatusModal();
                openCompletionModal(statusId);
                return false;
            }
        }
        
        // For other statuses, show the general confirmation modal
        closeStatusModal();
        openConfirmationModal(statusId, statusName);
    });

    // Update window.onclick to handle all modals
    window.onclick = function(event) {
        const statusModal = document.getElementById('statusModal');
        const completionModal = document.getElementById('completionModal');
        const confirmationModal = document.getElementById('confirmationModal');
        
        if (event.target === statusModal) {
            closeStatusModal();
        }
        
        if (event.target === completionModal) {
            closeCompletionModal();
        }
        
        if (event.target === confirmationModal) {
            closeConfirmationModal();
        }
    }
</script>
</body>
</html>
