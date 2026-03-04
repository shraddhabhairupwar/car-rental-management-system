<?php

// ---------- DB CONNECTION ----------
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';      // 
$DB_NAME = 'car_rentals';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    die("DB Connect Failed: " . $mysqli->connect_error);
}

// ---------- UTIL: sanitize
function h($s){ return htmlspecialchars($s, ENT_QUOTES); }

// ---------- ACTIONS: Add / Delete / Create / Return ----------
$notice = '';

// Add Car
if (isset($_POST['action']) && $_POST['action'] === 'add_car') {
    $model = $_POST['car_model'] ?? '';
    $number = $_POST['car_number'] ?? '';
    $type = $_POST['car_type'] ?? '';
    $price = floatval($_POST['price_per_day'] ?? 0);

    $stmt = $mysqli->prepare("INSERT INTO cars (car_model, car_number, car_type, price_per_day) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('sssd', $model, $number, $type, $price);
    if ($stmt->execute()) $notice = "Car added successfully.";
    else $notice = "Error adding car: " . $stmt->error;
    $stmt->close();
}

// Delete Car
if (isset($_GET['delete_car'])) {
    $id = intval($_GET['delete_car']);
    $stmt = $mysqli->prepare("DELETE FROM cars WHERE car_id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) $notice = "Car deleted.";
    else $notice = "Error deleting car: " . $stmt->error;
    $stmt->close();
}

// Add Customer
if (isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    $name = $_POST['cust_name'] ?? '';
    $phone = $_POST['cust_phone'] ?? '';
    $idproof = $_POST['cust_idproof'] ?? '';
    $address = $_POST['cust_address'] ?? '';

    $stmt = $mysqli->prepare("INSERT INTO customers (name, phone, id_proof, address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $name, $phone, $idproof, $address);
    if ($stmt->execute()) $notice = "Customer added successfully.";
    else $notice = "Error adding customer: " . $stmt->error;
    $stmt->close();
}

// Delete Customer
if (isset($_GET['delete_customer'])) {
    $id = intval($_GET['delete_customer']);
    $stmt = $mysqli->prepare("DELETE FROM customers WHERE customer_id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) $notice = "Customer deleted.";
    else $notice = "Cannot delete customer: customer data is linked to existing rentals.";
    $stmt->close();
}

// Create Rental (with overlap check + total calc)
if (isset($_POST['action']) && $_POST['action'] === 'create_rental') {
    $customer_id = intval($_POST['r_customer']);
    $car_id = intval($_POST['r_car']);
    $start = $_POST['r_start'] ?? '';
    $end = $_POST['r_end'] ?? '';

    // basic date validation
    if (!$start || !$end || strtotime($end) < strtotime($start)) {
        $notice = "Invalid dates. Ensure end date >= start date.";
    } else {
        // 1) check overlap: any rental for same car where periods intersect and not returned
        $stmt = $mysqli->prepare("
            SELECT COUNT(*) FROM rentals 
            WHERE car_id = ? AND returned = 0
              AND NOT ( ? < start_date OR ? > end_date )
        ");
        $stmt->bind_param('iss', $car_id, $start, $end);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();

        if ($cnt > 0) {
            $notice = "Car is already booked for the selected dates.";
        } else {
            // get price_per_day
            $stmt = $mysqli->prepare("SELECT price_per_day FROM cars WHERE car_id = ?");
            $stmt->bind_param('i', $car_id);
            $stmt->execute();
            $stmt->bind_result($price_per_day);
            if (!$stmt->fetch()) {
                $notice = "Selected car not found.";
                $stmt->close();
            } else {
                $stmt->close();
                // calculate days (inclusive)
                $days = (int)( (strtotime($end) - strtotime($start)) / 86400 ) + 1;
                if ($days < 1) $days = 1;
                $total = $days * floatval($price_per_day);

                // insert rental
                $ins = $mysqli->prepare("INSERT INTO rentals (customer_id, car_id, start_date, end_date, days, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->bind_param('iissid', $customer_id, $car_id, $start, $end, $days, $total);
                if ($ins->execute()) {
                    $notice = "Rental created. Total: ₹" . number_format($total,2);
                    // mark car as booked
                    $u = $mysqli->prepare("UPDATE cars SET status='booked' WHERE car_id = ?");
                    $u->bind_param('i', $car_id);
                    $u->execute();
                    $u->close();
                } else {
                    $notice = "Error creating rental: " . $ins->error;
                }
                $ins->close();
            }
        }
    }
}

// Mark Returned
if (isset($_GET['return_rental'])) {
    $rental_id = intval($_GET['return_rental']);
    // set returned flag
    $stmt = $mysqli->prepare("UPDATE rentals SET returned = 1 WHERE rental_id = ?");
    $stmt->bind_param('i', $rental_id);
    if ($stmt->execute()) {
        // free the car if no other active rental overlaps it (simple approach: set car available)
        // get car_id
        $res = $mysqli->query("SELECT car_id FROM rentals WHERE rental_id = $rental_id");
        if ($res && $row = $res->fetch_assoc()) {
            $car_id = intval($row['car_id']);
            // if no other active rentals for this car, set available
            $stmt2 = $mysqli->prepare("SELECT COUNT(*) FROM rentals WHERE car_id = ? AND returned = 0");
            $stmt2->bind_param('i', $car_id);
            $stmt2->execute();
            $stmt2->bind_result($cntActive);
            $stmt2->fetch();
            $stmt2->close();
            if ($cntActive == 0) {
                $u = $mysqli->prepare("UPDATE cars SET status='available' WHERE car_id = ?");
                $u->bind_param('i', $car_id);
                $u->execute();
                $u->close();
            }
        }
        $notice = "Rental marked returned and car availability updated.";
    } else {
        $notice = "Error marking returned: " . $stmt->error;
    }
    $stmt->close();
}

// ---------- READ LISTS FOR UI ----------
$cars = $mysqli->query("SELECT * FROM cars ORDER BY created_at DESC");
$customers = $mysqli->query("SELECT * FROM customers ORDER BY created_at DESC");
$rentals = $mysqli->query("
    SELECT r.*, c.car_model, c.car_number, cust.name AS customer_name
    FROM rentals r
    JOIN cars c ON r.car_id = c.car_id
    JOIN customers cust ON r.customer_id = cust.customer_id
    ORDER BY r.created_at DESC
");

// ---------- HTML + CSS (modern dashboard) ----------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Owner Dashboard — Car Rental Management</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{
  --bg:linear-gradient(135deg, #eebbd5, #2f284e); --card:#fff; --muted:#6b7280; --accent:#431c52;
  --success:grey; --danger:#ef4444;
  --shadow: 0 6px 18px rgba(15,23,42,0.08);
  font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif;
}
body{margin:0;background:var(--bg);color:#0f172a;}
.container{max-width:1150px;margin:30px auto;padding:20px;}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.brand{display:flex;gap:12px;align-items:center;}
.logo{width:48px;height:48px;border-radius:10px;background:black;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;}
h1{margin:0;font-size:40px;}
.sub{color:var(--muted);font-size:13px;}

.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:22px;}
.card{background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow);}
.stat{font-size:28px;font-weight:700;margin:6px 0;}
.small{color:var(--muted);font-size:13px;}

.flex{display:flex;gap:18px;}
.col{display:flex;flex-direction:column;gap:12px;}

/* forms & tables */
form{display:flex;flex-direction:column;gap:10px;}
input, select {padding:10px;border-radius:8px;border:1px solid #e6eef7;background:#fff;}
button{padding:10px;border-radius:8px;border:0;background:var(--accent);color:white;cursor:pointer;}
button.danger{background:var(--danger);}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid #eef3fb;text-align:left;font-size:14px;}
thead th{background:transparent;color:var(--muted);font-size:13px;}
.badge{display:inline-block;padding:6px 9px;border-radius:999px;font-size:13px;background:#eef6ff;color:var(--accent);}

/* responsive */
@media (max-width:980px){.grid{grid-template-columns:repeat(2,1fr);} .flex{flex-direction:column;}}
@media (max-width:640px){.grid{grid-template-columns:1fr;} .container{padding:12px;}}
.notice{padding:10px;border-radius:8px;background:#e6ffe9;color:var(--success);margin-bottom:12px;}
.error{background:#ffe8e8;color:var(--danger);padding:10px;border-radius:8px;margin-bottom:12px;}
.controls{display:flex;gap:10px;flex-wrap:wrap;}
.small-muted{font-size:12px;color:var(--muted);}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="brand">
      <div class="logo">CAR</div>
      <div>
        <h1>Car Rental System</h1>

      </div>
    </div>
    
  </div>

  <?php if ($notice): ?>
    <div class="notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <div class="small">Cars</div>
      <div class="stat"><?= $cars->num_rows ?></div>
      <div class="small-muted">Total cars in system</div>
    </div>

    <div class="card">
      <div class="small">Customers</div>
      <div class="stat"><?= $customers->num_rows ?></div>
      <div class="small-muted">Registered customers</div>
    </div>

    <div class="card">
      <div class="small">Rentals</div>
      <div class="stat"><?= $rentals->num_rows ?></div>
      <div class="small-muted">Total rentals</div>
    </div>
  </div>

  <div class="flex">
    <div class="col" style="flex:1.1;min-width:320px;">
      <div class="card">
        <h3 style="margin:0 0 8px 0;">Add New Car</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_car">
          <input name="car_model" placeholder="Car model (eg. Toyota Innova)" required>
          <input name="car_number" placeholder="Car number (eg. TS09AB1234)" required>
          <input name="car_type" placeholder="Car type (SUV / Sedan / Hatchback)" required>
          <input name="price_per_day" placeholder="Price per day (numbers)" type="number" step="0.01" required>
          <div class="controls"><button type="submit">Add Car</button></div>
        </form>
      </div>

      <div class="card">
        <h3 style="margin:0 0 8px 0;">Add New Customer</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_customer">
          <input name="cust_name" placeholder="Customer name" required>
          <input name="cust_phone" placeholder="Phone">
          <input name="cust_idproof" placeholder="ID proof (DL / Aadhar)">
          <input name="cust_address" placeholder="Address">
          <div class="controls"><button type="submit">Add Customer</button></div>
        </form>
      </div>

      <div class="card">
        <h3 style="margin:0 0 8px 0;">Create Rental</h3>
        <form method="post">
          <input type="hidden" name="action" value="create_rental">
          <label class="small-muted">Select customer</label>
          <select name="r_customer" required>
            <option value="">-- Select customer --</option>
            <?php
            $customers2 = $mysqli->query("SELECT customer_id, name, phone FROM customers ORDER BY name ASC");
            while ($c = $customers2->fetch_assoc()) {
                echo "<option value='{$c['customer_id']}'>" . h($c['name']) . " (" . h($c['phone']) . ")</option>";
            }
            ?>
          </select>

          <label class="small-muted">Select car</label>
          <select name="r_car" required>
            <option value="">-- Select car --</option>
            <?php
            $cars2 = $mysqli->query("SELECT car_id, car_model, car_number, price_per_day, status FROM cars ORDER BY car_model ASC");
            while ($c = $cars2->fetch_assoc()) {
                $lbl = h($c['car_model']) . " - " . h($c['car_number']) . " (₹" . number_format($c['price_per_day'],2) . ")";
                if ($c['status'] !== 'available') $lbl .= " [" . h(strtoupper($c['status'])) . "]";
                echo "<option value='{$c['car_id']}'>" . $lbl . "</option>";
            }
            ?>
          </select>

          <div style="display:flex;gap:10px;">
            <div style="flex:1;">
              <label class="small-muted">Start date</label>
              <input type="date" name="r_start" required>
            </div>
            <div style="flex:1;">
              <label class="small-muted">End date</label>
              <input type="date" name="r_end" required>
            </div>
          </div>

          <div class="controls"><button type="submit">Create Rental</button></div>
        </form>
      </div>
    </div>

    <div style="flex:1;min-width:320px;">
      <div class="card" style="margin-bottom:14px;">
        <h3 style="margin:0 0 8px 0;">Car List</h3>
        <table>
          <thead>
            <tr><th>Model</th><th>Number</th><th>Type</th><th>Price/Day</th><th>Status</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php
            $cars3 = $mysqli->query("SELECT * FROM cars ORDER BY created_at DESC");
            while ($c = $cars3->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . h($c['car_model']) . "</td>";
                echo "<td>" . h($c['car_number']) . "</td>";
                echo "<td>" . h($c['car_type']) . "</td>";
                echo "<td>₹" . number_format($c['price_per_day'],2) . "</td>";
                echo "<td><span class='badge'>" . h($c['status']) . "</span></td>";
                echo "<td><a href='?delete_car=" . intval($c['car_id']) . "' onclick=\"return confirm('Delete car?')\" style='color:#ef4444'>Delete</a></td>";
                echo "</tr>";
            }
            ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3 style="margin:0 0 8px 0;">Customer List</h3>
        <table>
          <thead><tr><th>Name</th><th>Phone</th><th>ID Proof</th><th>Action</th></tr></thead>
          <tbody>
            <?php
            $custs = $mysqli->query("SELECT * FROM customers ORDER BY created_at DESC");
            while ($cc = $custs->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . h($cc['name']) . "</td>";
                echo "<td>" . h($cc['phone']) . "</td>";
                echo "<td>" . h($cc['id_proof']) . "</td>";
                echo "<td><a href='?delete_customer=" . intval($cc['customer_id']) . "' onclick=\"return confirm('Delete customer?')\" style='color:#ef4444'>Delete</a></td>";
                echo "</tr>";
            }
            ?>
          </tbody>
        </table>
      </div>

    </div>
  </div> <!-- end flex -->

  <div class="card" style="margin-top:18px;">
    <h3 style="margin:0 0 8px 0;">Rental Records</h3>
    <table>
      <thead>
        <tr><th>Rental ID</th><th>Customer</th><th>Car</th><th>From</th><th>To</th><th>Days</th><th>Total</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php
        $rs = $mysqli->query("
          SELECT r.*, c.car_model, c.car_number, cust.name AS customer_name 
          FROM rentals r
          JOIN cars c ON r.car_id = c.car_id
          JOIN customers cust ON r.customer_id = cust.customer_id
          ORDER BY r.created_at DESC
        ");
        while ($r = $rs->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . intval($r['rental_id']) . "</td>";
            echo "<td>" . h($r['customer_name']) . "</td>";
            echo "<td>" . h($r['car_model']) . " (" . h($r['car_number']) . ")</td>";
            echo "<td>" . h($r['start_date']) . "</td>";
            echo "<td>" . h($r['end_date']) . "</td>";
            echo "<td>" . intval($r['days']) . "</td>";
            echo "<td>₹" . number_format($r['total_amount'],2) . "</td>";
            echo "<td>" . ($r['returned'] ? "<span class='small-muted'>Returned</span>" : "<span style='color:#f59e0b'>Active</span>") . "</td>";
            if (!$r['returned']) {
                echo "<td><a href='?return_rental=" . intval($r['rental_id']) . "' onclick=\"return confirm('Mark returned?')\">Mark Returned</a></td>";
            } else {
                echo "<td class='small-muted'>—</td>";
            }
            echo "</tr>";
        }
        ?>
      </tbody>
    </table>
  </div>

  </div>
</body>
</html>