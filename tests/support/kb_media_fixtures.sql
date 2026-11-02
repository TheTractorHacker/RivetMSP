-- Fixtures for tests/kb_media_embeds.php (GitHub #7, "KB v2" media/embeds slice).
-- Loaded into the schema-only scratch DB built from db.sql. No live data.

INSERT INTO companies (company_id, company_name) VALUES (1, 'Scratch Co');

INSERT INTO settings (company_id, config_current_database_version, config_module_enable_kb, config_client_portal_enable)
VALUES (1, '2.6.131', 1, 1);

-- Modules: only module_kb is needed for this test.
INSERT INTO modules (module_id, module_name) VALUES (1, 'module_kb');

-- Roles: 1 = MSP admin (bypasses client scoping), 2 = restricted technician (module_kb read, no admin bypass).
INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1, 'MSP Admin', 1);
INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (2, 'Restricted Technician', 0);
INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2, 1, 1);

-- Agents: user 1 = MSP admin, user 2 = technician restricted to Client A only (user_client_permissions row below).
INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id)
VALUES (1, 'Admin Tech', 'admin@example.test', '$2y$10$abcdefghijklmnopqrstuuJ8l8l8l8l8l8l8l8l8l8l8l8l8l8l8l8', 1, 1, 1);
INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id)
VALUES (2, 'Tech A', 'tech-a@example.test', '$2y$10$abcdefghijklmnopqrstuuJ8l8l8l8l8l8l8l8l8l8l8l8l8l8l8l8', 1, 1, 2);

-- Clients A and B, plus one contact per client for the portal sessions.
INSERT INTO clients (client_id, client_name, client_currency_code, client_net_terms)
VALUES (10, 'Client A', 'USD', 30);
INSERT INTO clients (client_id, client_name, client_currency_code, client_net_terms)
VALUES (20, 'Client B', 'USD', 30);

INSERT INTO contacts (contact_id, contact_name, contact_client_id) VALUES (100, 'Contact A', 10);
INSERT INTO contacts (contact_id, contact_name, contact_client_id) VALUES (200, 'Contact B', 20);

-- Every portal login also has a `users` row (user_type = 2, "client") - client/includes/check_login.php looks
-- one up by $_SESSION['user_id'] to re-verify status/type/archived on every request, independently of
-- $_SESSION['contact_id']/$_SESSION['client_id'].
INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status)
VALUES (10001, 'Contact A', 'contact-a@example.test', 'x', 2, 1);
INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status)
VALUES (10002, 'Contact B', 'contact-b@example.test', 'x', 2, 1);

-- Technician 2 (Tech A) is restricted to Client A only. Having ANY row here switches the
-- app-wide "no restriction configured" fail-open default off for this user (see
-- agent/includes/kb_media_auth.php's kbMediaClientAccessOk() docblock).
INSERT INTO user_client_permissions (user_id, client_id) VALUES (2, 10);

-- Articles: one scoped to Client A (visible to the portal), one global/company-wide (client_id = 0), one
-- scoped to Client B (used only to prove Tech A - restricted to Client A - is denied Client B's media/embeds
-- too, not just the portal contacts).
INSERT INTO kb_articles (kb_article_id, kb_article_title, kb_article_content, kb_article_client_id, kb_article_client_visible)
VALUES (1, 'Client A Runbook', '<p>client A runbook</p>', 10, 1);
INSERT INTO kb_articles (kb_article_id, kb_article_title, kb_article_content, kb_article_client_id, kb_article_client_visible)
VALUES (2, 'Global Article', '<p>global</p>', 0, 1);
INSERT INTO kb_articles (kb_article_id, kb_article_title, kb_article_content, kb_article_client_id, kb_article_client_visible)
VALUES (3, 'Client B Runbook', '<p>client B runbook</p>', 20, 1);

-- One attachment per article, referencing a real file written under uploads/kb/<id>/ by the test runner.
INSERT INTO kb_article_attachments (kb_article_attachment_id, kb_article_attachment_name, kb_article_attachment_reference_name, kb_article_attachment_kb_article_id)
VALUES (1, 'client-a-secret.txt', 'clientarefabc12.txt', 1);
INSERT INTO kb_article_attachments (kb_article_attachment_id, kb_article_attachment_name, kb_article_attachment_reference_name, kb_article_attachment_kb_article_id)
VALUES (2, 'global.txt', 'globalref00ab12.txt', 2);
INSERT INTO kb_article_attachments (kb_article_attachment_id, kb_article_attachment_name, kb_article_attachment_reference_name, kb_article_attachment_kb_article_id)
VALUES (3, 'client-b-secret.txt', 'clientbrefxyz12.txt', 3);

-- One embed per article.
INSERT INTO kb_article_embeds (kb_article_embed_id, kb_article_embed_kb_article_id, kb_article_embed_name, kb_article_embed_untrusted_html, kb_article_embed_height)
VALUES (1, 1, 'Client A Tool', '<p>client A embed</p>', 300);
INSERT INTO kb_article_embeds (kb_article_embed_id, kb_article_embed_kb_article_id, kb_article_embed_name, kb_article_embed_untrusted_html, kb_article_embed_height)
VALUES (2, 2, 'Global Tool', '<p>global embed</p>', 300);
INSERT INTO kb_article_embeds (kb_article_embed_id, kb_article_embed_kb_article_id, kb_article_embed_name, kb_article_embed_untrusted_html, kb_article_embed_height)
VALUES (3, 3, 'Client B Tool', '<p>client B embed</p>', 300);
