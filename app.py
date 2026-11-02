"""PayMatch: bank-statement matching with a separate human dispatch decision."""
import csv
import hashlib
import hmac
import io
import os
import re
import secrets
import sqlite3
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from functools import wraps

from flask import Flask, abort, flash, g, redirect, render_template, request, session, url_for

app = Flask(__name__)
secret_key = os.environ.get("SECRET_KEY", "")
if len(secret_key) < 32:
    raise RuntimeError("Set SECRET_KEY to a random value of at least 32 characters")
app.secret_key = secret_key
app.config.update(
    MAX_CONTENT_LENGTH=2 * 1024 * 1024,
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",
    SESSION_COOKIE_SECURE=os.environ.get("COOKIE_SECURE", "false").lower() == "true",
)
DB_PATH = os.environ.get("PAYMATCH_DB", "paymatch.sqlite3")
ORDER_CODE = re.compile(r"^[A-Z0-9][A-Z0-9_-]{2,39}$")


def now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def db():
    if "db" not in g:
        g.db = sqlite3.connect(DB_PATH, timeout=15)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA foreign_keys=ON")
        g.db.execute("PRAGMA busy_timeout=15000")
    return g.db


@app.teardown_appcontext
def close_db(_error):
    connection = g.pop("db", None)
    if connection is not None:
        connection.close()


def init_db():
    os.makedirs(os.path.dirname(os.path.abspath(DB_PATH)), exist_ok=True)
    with app.app_context():
        conn = db()
        conn.executescript("""
            PRAGMA journal_mode=WAL;
            CREATE TABLE IF NOT EXISTS users (
              id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
              role TEXT NOT NULL CHECK(role IN ('admin','operator','approver')),
              created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS orders (
              id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, customer TEXT NOT NULL,
              amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
              status TEXT NOT NULL DEFAULT 'awaiting_transfer'
                CHECK(status IN ('awaiting_transfer','candidate','released')),
              matched_transaction_id INTEGER UNIQUE, created_by INTEGER NOT NULL,
              created_at TEXT NOT NULL, released_by INTEGER, released_at TEXT,
              release_note TEXT, FOREIGN KEY(created_by) REFERENCES users(id),
              FOREIGN KEY(released_by) REFERENCES users(id)
            );
            CREATE TABLE IF NOT EXISTS imports (
              id INTEGER PRIMARY KEY, file_name TEXT NOT NULL, account_label TEXT NOT NULL,
              file_sha256 TEXT NOT NULL UNIQUE, row_count INTEGER NOT NULL,
              imported_by INTEGER NOT NULL, created_at TEXT NOT NULL,
              FOREIGN KEY(imported_by) REFERENCES users(id)
            );
            CREATE TABLE IF NOT EXISTS transactions (
              id INTEGER PRIMARY KEY, import_id INTEGER NOT NULL, account_label TEXT NOT NULL,
              booked_at TEXT NOT NULL, bank_id TEXT NOT NULL, reference TEXT NOT NULL,
              amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
              fingerprint TEXT NOT NULL, duplicate_of INTEGER,
              matched_order_id INTEGER UNIQUE,
              FOREIGN KEY(import_id) REFERENCES imports(id),
              FOREIGN KEY(duplicate_of) REFERENCES transactions(id),
              FOREIGN KEY(matched_order_id) REFERENCES orders(id)
            );
            CREATE INDEX IF NOT EXISTS tx_fingerprint ON transactions(fingerprint);
            CREATE TABLE IF NOT EXISTS audit (
              id INTEGER PRIMARY KEY, actor_id INTEGER, action TEXT NOT NULL,
              entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL,
              detail TEXT NOT NULL, created_at TEXT NOT NULL,
              FOREIGN KEY(actor_id) REFERENCES users(id)
            );
        """)
        if not conn.execute("SELECT 1 FROM users LIMIT 1").fetchone():
            email, password = os.environ.get("ADMIN_EMAIL"), os.environ.get("ADMIN_PASSWORD")
            if email and password and len(password) >= 12:
                conn.execute("INSERT OR IGNORE INTO users(email,password_hash,role,created_at) VALUES(?,?,?,?)",
                             (email.strip().lower(), hash_password(password), "admin", now()))
                conn.commit()
            else:
                raise RuntimeError("Set ADMIN_EMAIL and ADMIN_PASSWORD (at least 12 characters) for first startup")


def hash_password(password):
    salt = secrets.token_bytes(16)
    digest = hashlib.scrypt(password.encode(), salt=salt, n=2**14, r=8, p=1)
    return f"{salt.hex()}:{digest.hex()}"


def check_password(password, stored):
    try:
        salt_hex, expected = stored.split(":", 1)
        digest = hashlib.scrypt(password.encode(), salt=bytes.fromhex(salt_hex), n=2**14, r=8, p=1)
        return hmac.compare_digest(digest, bytes.fromhex(expected))
    except (ValueError, TypeError):
        return False


def money_to_cents(value):
    try:
        number = Decimal(str(value).replace(",", "").strip())
        if not number.is_finite() or number <= 0 or number.as_tuple().exponent < -2:
            raise ValueError()
        return int(number * 100)
    except (InvalidOperation, ValueError):
        raise ValueError("Amount must be positive with at most two decimal places")


def money(cents):
    return f"{Decimal(cents) / 100:,.2f}"


app.jinja_env.filters["money"] = money


def audit(actor, action, entity_type, entity_id, detail):
    db().execute("INSERT INTO audit(actor_id,action,entity_type,entity_id,detail,created_at) VALUES(?,?,?,?,?,?)",
                 (actor, action, entity_type, entity_id, detail, now()))


@app.before_request
def load_user():
    g.user = db().execute("SELECT id,email,role FROM users WHERE id=?", (session.get("user_id", -1),)).fetchone()
    if request.method == "POST":
        token = session.get("csrf_token", "")
        if not token or not hmac.compare_digest(token, request.form.get("csrf_token", "")):
            abort(400, "Invalid form token. Refresh the page and try again.")


@app.context_processor
def template_context():
    if "csrf_token" not in session:
        session["csrf_token"] = secrets.token_urlsafe(32)
    return {"csrf_token": session["csrf_token"], "current_user": g.user}


def roles(*allowed):
    def decorator(func):
        @wraps(func)
        def wrapped(*args, **kwargs):
            if not g.user:
                return redirect(url_for("login"))
            if allowed and g.user["role"] not in allowed:
                abort(403)
            return func(*args, **kwargs)
        return wrapped
    return decorator


@app.route("/login", methods=["GET", "POST"])
def login():
    if request.method == "POST":
        user = db().execute("SELECT * FROM users WHERE email=?", (request.form.get("email", "").strip().lower(),)).fetchone()
        if user and check_password(request.form.get("password", ""), user["password_hash"]):
            session.clear()
            session["user_id"] = user["id"]
            session["csrf_token"] = secrets.token_urlsafe(32)
            audit(user["id"], "login", "user", user["id"], "Successful sign in")
            db().commit()
            return redirect(url_for("overview"))
        flash("Email or password is incorrect.", "error")
    return render_template("login.html")


@app.post("/logout")
@roles()
def logout():
    audit(g.user["id"], "logout", "user", g.user["id"], "Signed out")
    db().commit()
    session.clear()
    return redirect(url_for("login"))


def reconcile():
    """Only unique, exact amount + order-code candidates are linked. Never release here."""
    conn = db()
    # New imports can make a previously unique match ambiguous. Revoke it before
    # considering any new links. A released order is never silently changed.
    for order in conn.execute("SELECT * FROM orders WHERE status='candidate'").fetchall():
        candidate = conn.execute("SELECT id FROM transactions WHERE id=?", (order["matched_transaction_id"],)).fetchone()
        linked = conn.execute("SELECT reference FROM transactions WHERE id=?", (order["matched_transaction_id"],)).fetchone()
        rival_orders = [other for other in conn.execute("SELECT code FROM orders WHERE amount_cents=? AND id<>?",
            (order["amount_cents"], order["id"])).fetchall()
            if linked and other["code"] in re.findall(r"[A-Z0-9_-]+", linked["reference"].upper())]
        rivals = [tx for tx in conn.execute("""SELECT id,reference FROM transactions
            WHERE amount_cents=? AND duplicate_of IS NULL AND id<>?""",
            (order["amount_cents"], order["matched_transaction_id"])).fetchall()
            if order["code"] in re.findall(r"[A-Z0-9_-]+", tx["reference"].upper())]
        if not candidate or rivals or rival_orders:
            conn.execute("UPDATE transactions SET matched_order_id=NULL WHERE id=?", (order["matched_transaction_id"],))
            conn.execute("UPDATE orders SET status='awaiting_transfer',matched_transaction_id=NULL WHERE id=?", (order["id"],))
            audit(None, "candidate_revoked", "order", order["id"], "New bank records made this match ambiguous")
    orders = conn.execute("SELECT * FROM orders WHERE status='awaiting_transfer'").fetchall()
    for order in orders:
        matches = conn.execute("""SELECT * FROM transactions WHERE amount_cents=? AND duplicate_of IS NULL
            AND matched_order_id IS NULL""", (order["amount_cents"],)).fetchall()
        matches = [tx for tx in matches if order["code"] in re.findall(r"[A-Z0-9_-]+", tx["reference"].upper())]
        if len(matches) != 1:
            continue
        tx = matches[0]
        # A transaction also cannot qualify for two orders (codes may overlap).
        competing = conn.execute("SELECT code FROM orders WHERE status='awaiting_transfer' AND amount_cents=? AND id<>?",
                                 (order["amount_cents"], order["id"])).fetchall()
        if any(other["code"] in re.findall(r"[A-Z0-9_-]+", tx["reference"].upper()) for other in competing):
            continue
        conn.execute("UPDATE orders SET status='candidate',matched_transaction_id=? WHERE id=?", (tx["id"], order["id"]))
        conn.execute("UPDATE transactions SET matched_order_id=? WHERE id=?", (order["id"], tx["id"]))
        audit(None, "candidate_match", "order", order["id"], f"Transaction {tx['id']} matched exact code and amount; release still requires approval")


@app.get("/")
@roles()
def overview():
    conn = db()
    counts = {r["status"]: r["n"] for r in conn.execute("SELECT status,COUNT(*) n FROM orders GROUP BY status")}
    exceptions = conn.execute("""SELECT t.* FROM transactions t WHERE t.duplicate_of IS NOT NULL
        OR (t.matched_order_id IS NULL AND t.duplicate_of IS NULL) ORDER BY t.id DESC LIMIT 6""").fetchall()
    orders = conn.execute("""SELECT o.*,t.reference,t.booked_at,t.account_label FROM orders o
        LEFT JOIN transactions t ON t.id=o.matched_transaction_id ORDER BY o.id DESC LIMIT 8""").fetchall()
    return render_template("overview.html", counts=counts, exceptions=exceptions, orders=orders)


@app.route("/orders", methods=["GET", "POST"])
@roles("admin", "operator", "approver")
def orders():
    if request.method == "POST":
        if g.user["role"] not in ("admin", "operator"):
            abort(403)
        code = request.form.get("code", "").strip().upper()
        customer = request.form.get("customer", "").strip()
        try:
            amount = money_to_cents(request.form.get("amount", ""))
            if not ORDER_CODE.fullmatch(code) or not customer or len(customer) > 120:
                raise ValueError("Enter an order code (3–40 characters) and customer name")
            cur = db().execute("INSERT INTO orders(code,customer,amount_cents,created_by,created_at) VALUES(?,?,?,?,?)",
                               (code, customer, amount, g.user["id"], now()))
            audit(g.user["id"], "order_created", "order", cur.lastrowid, f"{code}; LKR {money(amount)}")
            reconcile()
            db().commit()
            flash("Order created. Dispatch remains blocked until approval.", "success")
            return redirect(url_for("orders"))
        except (ValueError, sqlite3.IntegrityError) as exc:
            db().rollback()
            flash("Order could not be created: " + str(exc), "error")
    rows = db().execute("""SELECT o.*,t.reference,t.booked_at,t.bank_id,u.email approver
        FROM orders o LEFT JOIN transactions t ON t.id=o.matched_transaction_id
        LEFT JOIN users u ON u.id=o.released_by ORDER BY o.id DESC""").fetchall()
    return render_template("orders.html", orders=rows)


@app.post("/orders/<int:order_id>/release")
@roles("admin", "approver")
def release(order_id):
    if request.form.get("confirm_bank_record") != "yes":
        flash("Confirm the transaction against your official bank record before release.", "error")
        return redirect(url_for("orders"))
    note = request.form.get("note", "").strip()
    if len(note) < 8 or len(note) > 500:
        flash("Add an approval note of 8–500 characters.", "error")
        return redirect(url_for("orders"))
    conn = db()
    conn.execute("BEGIN IMMEDIATE")
    order = conn.execute("""SELECT o.*,t.duplicate_of FROM orders o
        LEFT JOIN transactions t ON t.id=o.matched_transaction_id WHERE o.id=?""", (order_id,)).fetchone()
    if not order or order["status"] != "candidate" or order["duplicate_of"] is not None:
        conn.rollback()
        abort(409, "This order has no unique candidate match.")
    conflicting = [tx for tx in conn.execute("""SELECT reference FROM transactions WHERE amount_cents=?
        AND duplicate_of IS NULL AND id<>?""", (order["amount_cents"], order["matched_transaction_id"]))
        if order["code"] in re.findall(r"[A-Z0-9_-]+", tx["reference"].upper())]
    if conflicting:
        conn.rollback()
        abort(409, "More than one bank record matches this order; dispatch is blocked.")
    linked = conn.execute("SELECT reference FROM transactions WHERE id=?", (order["matched_transaction_id"],)).fetchone()
    competing_orders = [other for other in conn.execute("SELECT code FROM orders WHERE amount_cents=? AND id<>?",
        (order["amount_cents"], order_id)).fetchall()
        if other["code"] in re.findall(r"[A-Z0-9_-]+", linked["reference"].upper())]
    if competing_orders:
        conn.rollback()
        abort(409, "The bank reference also names another order at this amount; dispatch is blocked.")
    conn.execute("UPDATE orders SET status='released', released_by=?, released_at=?, release_note=? WHERE id=?",
                 (g.user["id"], now(), note, order_id))
    audit(g.user["id"], "dispatch_released", "order", order_id, note)
    conn.commit()
    flash(f"Dispatch released for {order['code']}. The approver is recorded.", "success")
    return redirect(url_for("orders"))


@app.route("/imports", methods=["GET", "POST"])
@roles("admin", "operator", "approver")
def imports():
    if request.method == "POST":
        if g.user["role"] not in ("admin", "operator"):
            abort(403)
        upload = request.files.get("file")
        account = request.form.get("account_label", "").strip()
        if not upload or not upload.filename or not account or len(account) > 80:
            flash("Choose a CSV and enter the bank account label.", "error")
            return redirect(url_for("imports"))
        raw = upload.read()
        file_hash = hashlib.sha256(account.encode() + b"\0" + raw).hexdigest()
        try:
            text = raw.decode("utf-8-sig")
            reader = csv.DictReader(io.StringIO(text, newline=""))
            required = {"date", "transaction_id", "reference", "amount", "type"}
            if not reader.fieldnames or not required.issubset({s.strip().lower() for s in reader.fieldnames}):
                raise ValueError("CSV needs date, transaction_id, reference, amount, type columns")
            reader.fieldnames = [s.strip().lower() for s in reader.fieldnames]
            parsed = []
            for line, row in enumerate(reader, start=2):
                if line > 5001:
                    raise ValueError("Maximum 5,000 transactions per import")
                if not any(row.values()):
                    continue
                if row.get("type", "").strip().lower() != "credit":
                    continue
                date = row.get("date", "").strip()
                try:
                    datetime.strptime(date, "%Y-%m-%d")
                except ValueError:
                    raise ValueError(f"Line {line}: date must be YYYY-MM-DD")
                bank_id = row.get("transaction_id", "").strip()
                reference = row.get("reference", "").strip()
                if not bank_id or len(bank_id) > 120 or len(reference) > 500:
                    raise ValueError(f"Line {line}: invalid transaction ID or reference")
                amount = money_to_cents(row.get("amount", ""))
                fingerprint = hashlib.sha256(f"{account}\0{bank_id}".encode()).hexdigest()
                parsed.append((account, date, bank_id, reference, amount, fingerprint))
            if not parsed:
                raise ValueError("No credit transactions found")
            conn = db()
            conn.execute("BEGIN IMMEDIATE")
            cur = conn.execute("INSERT INTO imports(file_name,account_label,file_sha256,row_count,imported_by,created_at) VALUES(?,?,?,?,?,?)",
                               (upload.filename[:200], account, file_hash, len(parsed), g.user["id"], now()))
            import_id = cur.lastrowid
            duplicates = 0
            for record in parsed:
                prior = conn.execute("SELECT id FROM transactions WHERE fingerprint=? ORDER BY id LIMIT 1", (record[-1],)).fetchone()
                if prior:
                    duplicates += 1
                conn.execute("""INSERT INTO transactions(import_id,account_label,booked_at,bank_id,reference,amount_cents,fingerprint,duplicate_of)
                    VALUES(?,?,?,?,?,?,?,?)""", (import_id, *record, prior["id"] if prior else None))
            audit(g.user["id"], "csv_imported", "import", import_id,
                  f"{len(parsed)} credits; {duplicates} duplicate bank IDs; account {account}")
            reconcile()
            conn.commit()
            flash(f"Imported {len(parsed)} credits; {duplicates} duplicate IDs flagged. Candidate matches still need approval.", "success")
            return redirect(url_for("imports"))
        except (UnicodeDecodeError, ValueError, sqlite3.IntegrityError) as exc:
            db().rollback()
            flash("Import failed: " + ("This file was already imported for this account" if isinstance(exc, sqlite3.IntegrityError) else str(exc)), "error")
    history = db().execute("""SELECT i.*,u.email FROM imports i JOIN users u ON u.id=i.imported_by ORDER BY i.id DESC""").fetchall()
    txs = db().execute("""SELECT t.*,o.code order_code FROM transactions t LEFT JOIN orders o ON o.id=t.matched_order_id ORDER BY t.id DESC LIMIT 30""").fetchall()
    return render_template("imports.html", history=history, transactions=txs)


@app.get("/audit")
@roles("admin", "approver")
def audit_log():
    entries = db().execute("""SELECT a.*,u.email FROM audit a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 200""").fetchall()
    return render_template("audit.html", entries=entries)


@app.route("/team", methods=["GET", "POST"])
@roles("admin")
def team():
    if request.method == "POST":
        email = request.form.get("email", "").strip().lower()
        password = request.form.get("password", "")
        role = request.form.get("role", "")
        if "@" not in email or len(email) > 254 or len(password) < 12 or role not in ("operator", "approver", "admin"):
            flash("Enter a valid email, role, and password of at least 12 characters.", "error")
        else:
            try:
                cur = db().execute("INSERT INTO users(email,password_hash,role,created_at) VALUES(?,?,?,?)",
                                   (email, hash_password(password), role, now()))
                audit(g.user["id"], "user_created", "user", cur.lastrowid, f"{email}; {role}")
                db().commit()
                flash("Team member added.", "success")
                return redirect(url_for("team"))
            except sqlite3.IntegrityError:
                db().rollback()
                flash("That email already exists.", "error")
    users = db().execute("SELECT id,email,role,created_at FROM users ORDER BY id").fetchall()
    return render_template("team.html", users=users)


@app.get("/healthz")
def healthz():
    db().execute("SELECT 1")
    return {"status": "ok"}


init_db()

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8000, debug=False)
