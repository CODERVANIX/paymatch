import io
import os
import tempfile
import unittest

_tmp = tempfile.TemporaryDirectory()
os.environ["PAYMATCH_DB"] = os.path.join(_tmp.name, "test.sqlite3")
os.environ["SECRET_KEY"] = "test-only-secret-key-with-32-characters"
os.environ["ADMIN_EMAIL"] = "admin@example.com"
os.environ["ADMIN_PASSWORD"] = "very-long-test-password"

import app as paymatch


class WorkflowTests(unittest.TestCase):
    def setUp(self):
        self.db_file = os.path.join(_tmp.name, "test.sqlite3")
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db_file + suffix)
            except FileNotFoundError:
                pass
        paymatch.init_db()
        self.client = paymatch.app.test_client()
        self.client.get("/login")
        with self.client.session_transaction() as session:
            token = session["csrf_token"]
        self.client.post("/login", data={"email": "admin@example.com", "password": "very-long-test-password", "csrf_token": token})

    def csrf(self):
        with self.client.session_transaction() as session:
            return session["csrf_token"]

    def create_order(self, code="PM-1001", amount="25000.00"):
        return self.client.post("/orders", data={"csrf_token": self.csrf(), "code": code,
            "customer": "Test customer", "amount": amount}, follow_redirects=True)

    def import_csv(self, rows, name="statement.csv"):
        data = "date,transaction_id,reference,amount,type\n" + rows
        return self.client.post("/imports", data={"csrf_token": self.csrf(), "account_label": "Business 01",
            "file": (io.BytesIO(data.encode()), name)}, content_type="multipart/form-data", follow_redirects=True)

    def status(self):
        with paymatch.app.app_context():
            return paymatch.db().execute("SELECT status FROM orders WHERE code='PM-1001'").fetchone()[0]

    def test_candidate_requires_human_release(self):
        self.create_order()
        self.import_csv("2026-11-02,B-1,Transfer PM-1001,25000.00,credit\n")
        self.assertEqual(self.status(), "candidate")
        response = self.client.post("/orders/1/release", data={"csrf_token": self.csrf(), "note": "Bank record checked"}, follow_redirects=True)
        self.assertIn(b"Confirm the transaction", response.data)
        self.assertEqual(self.status(), "candidate")
        self.client.post("/orders/1/release", data={"csrf_token": self.csrf(), "confirm_bank_record": "yes", "note": "Bank record checked"})
        self.assertEqual(self.status(), "released")
        with paymatch.app.app_context():
            event = paymatch.db().execute("SELECT action,actor_id FROM audit WHERE action='dispatch_released'").fetchone()
            self.assertEqual(event[0], "dispatch_released")
            self.assertIsNotNone(event[1])

    def test_later_ambiguous_transfer_revokes_candidate(self):
        self.create_order()
        self.import_csv("2026-11-02,B-1,PM-1001,25000.00,credit\n")
        self.assertEqual(self.status(), "candidate")
        self.import_csv("2026-11-03,B-2,PM-1001,25000.00,credit\n", "next.csv")
        self.assertEqual(self.status(), "awaiting_transfer")

    def test_second_order_code_revokes_candidate(self):
        self.create_order()
        self.import_csv("2026-11-02,B-1,PM-1001 PM-1002,25000.00,credit\n")
        self.assertEqual(self.status(), "candidate")
        self.create_order("PM-1002")
        self.assertEqual(self.status(), "awaiting_transfer")

    def test_duplicate_ids_and_nonexact_amount(self):
        self.create_order()
        self.import_csv("2026-11-02,B-1,PM-1001,24999.99,credit\n2026-11-02,B-1,PM-1001,25000.00,credit\n")
        self.assertEqual(self.status(), "awaiting_transfer")
        with paymatch.app.app_context():
            self.assertEqual(paymatch.db().execute("SELECT COUNT(*) FROM transactions WHERE duplicate_of IS NOT NULL").fetchone()[0], 1)

    def test_operator_cannot_release(self):
        with paymatch.app.app_context():
            conn = paymatch.db()
            conn.execute("INSERT INTO users(email,password_hash,role,created_at) VALUES(?,?,?,?)",
                ("operator@example.com", paymatch.hash_password("long-operator-password"), "operator", paymatch.now()))
            conn.commit()
        self.create_order()
        self.import_csv("2026-11-02,B-1,PM-1001,25000.00,credit\n")
        self.client.post("/logout", data={"csrf_token": self.csrf()})
        self.client.get("/login")
        self.client.post("/login", data={"csrf_token": self.csrf(), "email": "operator@example.com", "password": "long-operator-password"})
        response = self.client.post("/orders/1/release", data={"csrf_token": self.csrf(), "confirm_bank_record": "yes", "note": "Bank record checked"})
        self.assertEqual(response.status_code, 403)
        self.assertEqual(self.status(), "candidate")

    def test_csrf_blocks_mutation(self):
        response = self.client.post("/orders", data={"code": "PM-1001", "customer": "A", "amount": "1"})
        self.assertEqual(response.status_code, 400)


if __name__ == "__main__":
    unittest.main()
