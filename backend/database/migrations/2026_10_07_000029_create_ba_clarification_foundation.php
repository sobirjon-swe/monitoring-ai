<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'ba_receipts' => <<<'SQL'
variant varchar(10) NOT NULL CHECK(variant IN ('source','human')), operation varchar(40) NOT NULL,
family varchar(10), source_instance_id uuid, object_id varchar(200), source_revision bigint,
input_hash varchar(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'), envelope jsonb NOT NULL CHECK(jsonb_typeof(envelope)='object' AND octet_length(envelope::text)<=131072),
actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='ba-authority-v1'),
occurred_at timestamptz(6), source_updated_at timestamptz(6), received_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
CHECK((variant='source' AND num_nonnulls(family,source_instance_id,object_id,source_revision,occurred_at,source_updated_at)=6 AND source_revision>0 AND octet_length(object_id) BETWEEN 1 AND 200) OR (variant='human' AND num_nonnulls(family,source_instance_id,object_id,source_revision,occurred_at,source_updated_at)=0)),
CHECK((variant='source' AND ((family='party' AND operation='party.register') OR (family='request' AND operation='request.open') OR (family='answer' AND operation IN ('answer.record','answer.correct','answer.retract')))) OR (variant='human' AND operation IN ('request.revise','request.reopen','request.withdraw','context.add','review.confirm','review.reject','review.revoke'))),
UNIQUE(tenant_id,project_id,id,operation), UNIQUE(tenant_id,project_id,source_instance_id,family,object_id,source_revision),
UNIQUE(tenant_id,project_id,source_instance_id,family,object_id,source_revision,id),
FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,source_instance_id) REFERENCES source_instances(tenant_id,id)
SQL,
            'ba_source_heads' => <<<'SQL'
source_instance_id uuid NOT NULL, family varchar(10) NOT NULL, object_id varchar(200) NOT NULL,
source_revision bigint NOT NULL CHECK(source_revision>0), revision bigint NOT NULL CHECK(revision>0), receipt_id uuid NOT NULL, complete boolean NOT NULL,
UNIQUE(tenant_id,project_id,source_instance_id,family,object_id),
FOREIGN KEY(tenant_id,project_id,source_instance_id,family,object_id,source_revision,receipt_id) REFERENCES ba_receipts(tenant_id,project_id,source_instance_id,family,object_id,source_revision,id)
SQL,
            'ba_source_party_versions' => <<<'SQL'
receipt_id uuid NOT NULL, source_instance_id uuid NOT NULL, object_id varchar(200) NOT NULL, source_revision bigint NOT NULL CHECK(source_revision>0),
party_reference varchar(200) NOT NULL CHECK(octet_length(party_reference) BETWEEN 1 AND 200), party_kind varchar(10) NOT NULL CHECK(party_kind IN ('customer','internal','unknown')),
label varchar(500) NOT NULL CHECK(octet_length(label) BETWEEN 1 AND 500), predecessor_party_version_id uuid,
content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), synthetic boolean NOT NULL DEFAULT true CHECK(synthetic),
UNIQUE(receipt_id), UNIQUE(tenant_id,project_id,source_instance_id,id), UNIQUE(tenant_id,project_id,source_instance_id,object_id,id),
FOREIGN KEY(tenant_id,project_id,receipt_id) REFERENCES ba_receipts(tenant_id,project_id,id),
FOREIGN KEY(tenant_id,project_id,source_instance_id,object_id,predecessor_party_version_id) REFERENCES ba_source_party_versions(tenant_id,project_id,source_instance_id,object_id,id)
SQL,
            'ba_clarifications' => <<<'SQL'
source_instance_id uuid NOT NULL, object_id varchar(200) NOT NULL, revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0),
current_event_id uuid, current_request_id uuid, current_answer_id uuid, current_review_id uuid,
state varchar(12) NOT NULL DEFAULT 'open' CHECK(state IN ('open','answered','resolved','withdrawn')), original_requested_at timestamptz(6) NOT NULL,
UNIQUE(tenant_id,project_id,source_instance_id,object_id), FOREIGN KEY(tenant_id,source_instance_id) REFERENCES source_instances(tenant_id,id)
SQL,
            'ba_request_versions' => <<<'SQL'
clarification_id uuid NOT NULL, revision bigint NOT NULL CHECK(revision>0), episode bigint NOT NULL CHECK(episode>0), receipt_id uuid NOT NULL,
question varchar(4000) NOT NULL CHECK(octet_length(question) BETWEEN 1 AND 4000), requester_id uuid NOT NULL, coordinator_id uuid NOT NULL,
decision_party varchar(10) NOT NULL CHECK(decision_party IN ('customer','internal','unknown')), decision_owner_id uuid, decision_owner_party_version_id uuid,
CHECK(num_nonnulls(decision_owner_id,decision_owner_party_version_id)<=1), requested_at timestamptz(6) NOT NULL,
request_hash varchar(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'), links_hash varchar(64) NOT NULL CHECK(links_hash ~ '^[0-9a-f]{64}$'),
actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='ba-authority-v1'),
UNIQUE(receipt_id), UNIQUE(tenant_id,project_id,clarification_id,revision), UNIQUE(tenant_id,project_id,clarification_id,id),
UNIQUE(tenant_id,project_id,clarification_id,id,request_hash,links_hash),
FOREIGN KEY(tenant_id,project_id,clarification_id) REFERENCES ba_clarifications(tenant_id,project_id,id),
FOREIGN KEY(tenant_id,project_id,receipt_id) REFERENCES ba_receipts(tenant_id,project_id,id), FOREIGN KEY(tenant_id,requester_id) REFERENCES employees(tenant_id,id),
FOREIGN KEY(tenant_id,coordinator_id) REFERENCES employees(tenant_id,id), FOREIGN KEY(tenant_id,decision_owner_id) REFERENCES employees(tenant_id,id),
FOREIGN KEY(tenant_id,project_id,decision_owner_party_version_id) REFERENCES ba_source_party_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
SQL,
            'ba_request_links' => <<<'SQL'
clarification_id uuid NOT NULL, request_version_id uuid NOT NULL, task_version_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL,
relation varchar(10) NOT NULL CHECK(relation IN ('blocks','context')), requirement_version_id uuid NOT NULL, requirement_hash varchar(64) NOT NULL CHECK(requirement_hash ~ '^[0-9a-f]{64}$'),
UNIQUE(tenant_id,project_id,request_version_id,task_version_id,criterion_id),
FOREIGN KEY(tenant_id,project_id,clarification_id,request_version_id) REFERENCES ba_request_versions(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,task_version_id) REFERENCES task_versions(tenant_id,project_id,id),
FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id),
FOREIGN KEY(tenant_id,project_id,requirement_version_id) REFERENCES requirement_versions(tenant_id,project_id,id)
SQL,
            'ba_answer_versions' => <<<'SQL'
clarification_id uuid NOT NULL, request_version_id uuid NOT NULL, request_hash varchar(64) NOT NULL, links_hash varchar(64) NOT NULL,
receipt_id uuid NOT NULL, source_instance_id uuid NOT NULL, object_id varchar(200) NOT NULL, source_revision bigint NOT NULL CHECK(source_revision>0),
operation varchar(40) NOT NULL CHECK(operation IN ('answer.record','answer.correct','answer.retract')), predecessor_answer_version_id uuid,
author_party_version_id uuid, answer varchar(4000), response_at timestamptz(6), reason varchar(2000), answer_hash varchar(64) NOT NULL CHECK(answer_hash ~ '^[0-9a-f]{64}$'),
actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='ba-authority-v1'),
CHECK((operation='answer.retract' AND predecessor_answer_version_id IS NOT NULL AND num_nonnulls(author_party_version_id,answer,response_at)=0 AND octet_length(reason) BETWEEN 1 AND 2000) OR (operation IN ('answer.record','answer.correct') AND num_nonnulls(author_party_version_id,answer,response_at)=3 AND octet_length(answer) BETWEEN 1 AND 4000 AND reason IS NULL AND ((operation='answer.record' AND predecessor_answer_version_id IS NULL) OR (operation='answer.correct' AND predecessor_answer_version_id IS NOT NULL)))),
UNIQUE(receipt_id), UNIQUE(tenant_id,project_id,clarification_id,id), UNIQUE(tenant_id,project_id,clarification_id,request_version_id,id,request_hash,links_hash,answer_hash),
UNIQUE(tenant_id,project_id,clarification_id,source_instance_id,object_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,request_version_id,request_hash,links_hash) REFERENCES ba_request_versions(tenant_id,project_id,clarification_id,id,request_hash,links_hash),
FOREIGN KEY(tenant_id,project_id,receipt_id,operation) REFERENCES ba_receipts(tenant_id,project_id,id,operation),
FOREIGN KEY(tenant_id,project_id,clarification_id,source_instance_id,object_id,predecessor_answer_version_id) REFERENCES ba_answer_versions(tenant_id,project_id,clarification_id,source_instance_id,object_id,id),
FOREIGN KEY(tenant_id,project_id,author_party_version_id) REFERENCES ba_source_party_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
SQL,
            'ba_reviews' => <<<'SQL'
clarification_id uuid NOT NULL, request_version_id uuid NOT NULL, answer_version_id uuid NOT NULL, request_hash varchar(64) NOT NULL, links_hash varchar(64) NOT NULL, answer_hash varchar(64) NOT NULL,
receipt_id uuid NOT NULL, kind varchar(40) NOT NULL CHECK(kind IN ('review.confirm','review.reject','review.revoke')), disposition varchar(30), predecessor_review_id uuid,
reason varchar(2000) NOT NULL CHECK(octet_length(reason) BETWEEN 1 AND 2000), actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='ba-authority-v1'),
CHECK((kind='review.confirm' AND disposition IN ('no_scope_change','scope_change_required') AND predecessor_review_id IS NULL) OR (kind='review.reject' AND disposition IS NULL AND predecessor_review_id IS NULL) OR (kind='review.revoke' AND disposition IS NULL AND predecessor_review_id IS NOT NULL)),
UNIQUE(receipt_id), UNIQUE(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,request_version_id,answer_version_id,request_hash,links_hash,answer_hash) REFERENCES ba_answer_versions(tenant_id,project_id,clarification_id,request_version_id,id,request_hash,links_hash,answer_hash),
FOREIGN KEY(tenant_id,project_id,clarification_id,predecessor_review_id) REFERENCES ba_reviews(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,receipt_id,kind) REFERENCES ba_receipts(tenant_id,project_id,id,operation), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
SQL,
            'ba_events' => <<<'SQL'
clarification_id uuid NOT NULL, revision bigint NOT NULL CHECK(revision>0), kind varchar(40) NOT NULL CHECK(kind IN ('request.open','request.revise','request.reopen','request.withdraw','context.add','answer.record','answer.correct','answer.retract','review.confirm','review.reject','review.revoke')),
state varchar(12) NOT NULL CHECK(state IN ('open','answered','resolved','withdrawn')), request_version_id uuid NOT NULL, answer_version_id uuid, review_id uuid, receipt_id uuid NOT NULL,
context varchar(4000) CHECK(context IS NULL OR octet_length(context) BETWEEN 1 AND 4000), reason varchar(2000) CHECK(reason IS NULL OR octet_length(reason) BETWEEN 1 AND 2000), actor_id uuid NOT NULL,
authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='ba-authority-v1'), created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
UNIQUE(receipt_id), UNIQUE(tenant_id,project_id,clarification_id,revision), UNIQUE(tenant_id,project_id,clarification_id,revision,id), UNIQUE(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,request_version_id) REFERENCES ba_request_versions(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,answer_version_id) REFERENCES ba_answer_versions(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,review_id) REFERENCES ba_reviews(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,receipt_id,kind) REFERENCES ba_receipts(tenant_id,project_id,id,operation), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
SQL,
            'ba_scope_obligations' => <<<'SQL'
clarification_id uuid NOT NULL, origin_review_id uuid NOT NULL, origin_request_version_id uuid NOT NULL, origin_answer_version_id uuid NOT NULL,
request_hash varchar(64) NOT NULL, links_hash varchar(64) NOT NULL, answer_hash varchar(64) NOT NULL, created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
UNIQUE(tenant_id,project_id,clarification_id), FOREIGN KEY(tenant_id,project_id,clarification_id,origin_review_id) REFERENCES ba_reviews(tenant_id,project_id,clarification_id,id)
SQL,
            'ba_terminal_applicability' => <<<'SQL'
kind varchar(15) NOT NULL CHECK(kind IN ('receipt','request','answer','review','event','party','proof')), subject_id uuid NOT NULL,
proof_hash varchar(64) NOT NULL CHECK(proof_hash ~ '^[0-9a-f]{64}$'), reason_code varchar(40) NOT NULL CHECK(reason_code IN ('access_revoked','role_revoked','source_revoked')),
observed_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), UNIQUE(tenant_id,project_id,kind,subject_id,proof_hash)
SQL,
            'ba_request_bindings' => <<<'SQL'
requester_id uuid NOT NULL, operation varchar(40) NOT NULL, key varchar(100) NOT NULL CHECK(key ~ '^[A-Za-z0-9._:-]{1,100}$'), input_hash varchar(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'),
receipt_id uuid NOT NULL, party_version_id uuid, clarification_id uuid, request_version_id uuid, answer_version_id uuid, review_id uuid, event_id uuid,
created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), UNIQUE(tenant_id,project_id,requester_id,operation,key),
FOREIGN KEY(tenant_id,requester_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,project_id,receipt_id,operation) REFERENCES ba_receipts(tenant_id,project_id,id,operation),
FOREIGN KEY(tenant_id,project_id,party_version_id) REFERENCES ba_source_party_versions(tenant_id,project_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id) REFERENCES ba_clarifications(tenant_id,project_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,request_version_id) REFERENCES ba_request_versions(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,answer_version_id) REFERENCES ba_answer_versions(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,review_id) REFERENCES ba_reviews(tenant_id,project_id,clarification_id,id),
FOREIGN KEY(tenant_id,project_id,clarification_id,event_id) REFERENCES ba_events(tenant_id,project_id,clarification_id,id)
SQL,
        ];
        foreach ($tables as $table => $columns) {
            DB::statement("CREATE TABLE $table (id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, $columns, UNIQUE(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id))");
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE task_criterion_links ADD CONSTRAINT ba_task_criterion_exact_unique UNIQUE(tenant_id,project_id,task_version_id,criterion_id,criterion_version_id);
ALTER TABLE requirement_version_criteria ADD CONSTRAINT ba_requirement_criterion_exact_unique UNIQUE(tenant_id,project_id,requirement_version_id,criterion_id,criterion_version_id);
ALTER TABLE ba_request_links ADD CONSTRAINT ba_link_task_association_fk FOREIGN KEY(tenant_id,project_id,task_version_id,criterion_id,criterion_version_id) REFERENCES task_criterion_links(tenant_id,project_id,task_version_id,criterion_id,criterion_version_id);
ALTER TABLE ba_request_links ADD CONSTRAINT ba_link_requirement_membership_fk FOREIGN KEY(tenant_id,project_id,requirement_version_id,criterion_id,criterion_version_id) REFERENCES requirement_version_criteria(tenant_id,project_id,requirement_version_id,criterion_id,criterion_version_id);
ALTER TABLE ba_clarifications ADD CONSTRAINT ba_root_event_head_fk FOREIGN KEY(tenant_id,project_id,id,revision,current_event_id) REFERENCES ba_events(tenant_id,project_id,clarification_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE ba_clarifications ADD CONSTRAINT ba_root_request_head_fk FOREIGN KEY(tenant_id,project_id,id,current_request_id) REFERENCES ba_request_versions(tenant_id,project_id,clarification_id,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE ba_clarifications ADD CONSTRAINT ba_root_answer_head_fk FOREIGN KEY(tenant_id,project_id,id,current_answer_id) REFERENCES ba_answer_versions(tenant_id,project_id,clarification_id,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE ba_clarifications ADD CONSTRAINT ba_root_review_head_fk FOREIGN KEY(tenant_id,project_id,id,current_review_id) REFERENCES ba_reviews(tenant_id,project_id,clarification_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE FUNCTION guard_ba_projection() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e record; mutable text[];
BEGIN
 IF TG_TABLE_NAME='ba_source_heads' THEN
  mutable=ARRAY['revision','source_revision','receipt_id','complete'];
  IF NEW.revision<>OLD.revision+1 OR NEW.source_revision<=OLD.source_revision THEN RAISE EXCEPTION 'BA source must advance' USING ERRCODE='23514'; END IF;
 ELSE
  mutable=ARRAY['revision','current_event_id','current_request_id','current_answer_id','current_review_id','state'];
  IF NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'BA root must advance' USING ERRCODE='23514'; END IF;
  SELECT * INTO e FROM ba_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND clarification_id=NEW.id AND id=NEW.current_event_id AND revision=NEW.revision;
  IF NOT FOUND OR (NEW.state,NEW.current_request_id,NEW.current_answer_id,NEW.current_review_id) IS DISTINCT FROM (e.state,e.request_version_id,e.answer_version_id,e.review_id) THEN RAISE EXCEPTION 'BA head must match event' USING ERRCODE='23514'; END IF;
 END IF;
 IF (to_jsonb(NEW)-mutable) IS DISTINCT FROM (to_jsonb(OLD)-mutable) THEN RAISE EXCEPTION 'BA identity is immutable' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_ba_fact() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; q record; a record; v record; p record; body jsonb; allowed text[]; actual text[];
BEGIN
 IF TG_TABLE_NAME='ba_receipts' THEN
  IF NEW.authority_proof->>'actor_id' IS DISTINCT FROM NEW.actor_id::text OR NEW.authority_proof->>'tenant_id' IS DISTINCT FROM NEW.tenant_id::text OR NEW.authority_proof->>'project_id' IS DISTINCT FROM NEW.project_id::text THEN RAISE EXCEPTION 'BA receipt proof boundary' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSIF TG_TABLE_NAME='ba_clarifications' THEN
  IF NEW.revision<>0 OR num_nonnulls(NEW.current_event_id,NEW.current_request_id,NEW.current_answer_id,NEW.current_review_id)<>0 OR NEW.state<>'open' THEN RAISE EXCEPTION 'BA root cannot fake historical head' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSIF TG_TABLE_NAME='ba_request_links' THEN
  SELECT * INTO r FROM requirement_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.requirement_version_id;
  IF NOT FOUND OR r.content_hash<>NEW.requirement_hash THEN RAISE EXCEPTION 'BA requirement hash mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSIF TG_TABLE_NAME='ba_scope_obligations' THEN
  SELECT * INTO v FROM ba_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND clarification_id=NEW.clarification_id AND id=NEW.origin_review_id;
  IF NOT FOUND OR v.kind<>'review.confirm' OR v.disposition<>'scope_change_required' OR (NEW.origin_request_version_id,NEW.origin_answer_version_id,NEW.request_hash,NEW.links_hash,NEW.answer_hash) IS DISTINCT FROM (v.request_version_id,v.answer_version_id,v.request_hash,v.links_hash,v.answer_hash) THEN RAISE EXCEPTION 'BA obligation origin mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSIF TG_TABLE_NAME='ba_terminal_applicability' THEN
  IF NEW.kind<>'proof' THEN
   EXECUTE format('SELECT id FROM %I WHERE tenant_id=$1 AND project_id=$2 AND id=$3', CASE NEW.kind WHEN 'receipt' THEN 'ba_receipts' WHEN 'request' THEN 'ba_request_versions' WHEN 'answer' THEN 'ba_answer_versions' WHEN 'review' THEN 'ba_reviews' WHEN 'event' THEN 'ba_events' WHEN 'party' THEN 'ba_source_party_versions' END) INTO r USING NEW.tenant_id,NEW.project_id,NEW.subject_id;
   IF r.id IS NULL THEN RAISE EXCEPTION 'BA terminal subject mismatch' USING ERRCODE='23503'; END IF;
  END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO r FROM ba_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'BA receipt scope mismatch' USING ERRCODE='23503'; END IF;
 body=CASE WHEN r.variant='source' THEN r.envelope->'body' ELSE r.envelope END;
 IF TG_TABLE_NAME='ba_source_party_versions' THEN
  IF r.operation<>'party.register' OR (NEW.source_instance_id,NEW.object_id,NEW.source_revision) IS DISTINCT FROM (r.source_instance_id,r.object_id,r.source_revision) THEN RAISE EXCEPTION 'BA party receipt mismatch' USING ERRCODE='23503'; END IF;
  IF (to_jsonb(NEW)->'party_reference',to_jsonb(NEW)->'party_kind',to_jsonb(NEW)->'label',to_jsonb(NEW)->'predecessor_party_version_id') IS DISTINCT FROM (body->'party_reference',body->'party_kind',body->'label',body->'predecessor_party_version_id') THEN RAISE EXCEPTION 'BA party content mismatch' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='ba_request_versions' THEN
  IF r.operation NOT IN ('request.open','request.revise','request.reopen') THEN RAISE EXCEPTION 'BA request receipt type' USING ERRCODE='23503'; END IF;
  IF r.variant='human' THEN body=body->'request'; END IF;
  IF (NEW.question,NEW.requester_id::text,NEW.coordinator_id::text,NEW.decision_party,NEW.decision_owner_id::text,NEW.decision_owner_party_version_id::text) IS DISTINCT FROM (body->>'question',body->>'requester_id',body->>'coordinator_id',body->>'decision_party',body->>'decision_owner_id',body->>'decision_owner_party_version_id') THEN RAISE EXCEPTION 'BA request content mismatch' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='ba_answer_versions' THEN
  IF (NEW.source_instance_id,NEW.object_id,NEW.source_revision) IS DISTINCT FROM (r.source_instance_id,r.object_id,r.source_revision) OR NEW.operation<>r.operation THEN RAISE EXCEPTION 'BA answer source mismatch' USING ERRCODE='23503'; END IF;
  IF (NEW.clarification_id::text,NEW.request_version_id::text,NEW.request_hash,NEW.links_hash,NEW.author_party_version_id::text,NEW.answer,NEW.reason,NEW.predecessor_answer_version_id::text) IS DISTINCT FROM (body->>'clarification_id',body->>'request_version_id',body->>'request_hash',body->>'links_hash',body->>'author_party_version_id',body->>'answer',body->>'reason',body->>'predecessor_answer_version_id') THEN RAISE EXCEPTION 'BA answer content mismatch' USING ERRCODE='23514'; END IF;
  IF NEW.predecessor_answer_version_id IS NOT NULL THEN
   SELECT * INTO a FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.predecessor_answer_version_id;
   IF a.source_revision>=NEW.source_revision THEN RAISE EXCEPTION 'BA answer predecessor order' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='ba_reviews' THEN
  SELECT * INTO a FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.answer_version_id;
  IF NEW.kind='review.confirm' AND (a.operation='answer.retract' OR a.authority_proof->>'employee_id'=NEW.authority_proof->>'employee_id') THEN RAISE EXCEPTION 'BA confirmer must be distinct employee' USING ERRCODE='23514'; END IF;
  IF (NEW.kind,NEW.clarification_id::text,NEW.request_version_id::text,NEW.answer_version_id::text,NEW.request_hash,NEW.links_hash,NEW.answer_hash,NEW.disposition,NEW.reason,NEW.predecessor_review_id::text) IS DISTINCT FROM (body->>'kind',body->>'clarification_id',body->>'request_version_id',body->>'answer_version_id',body->>'request_hash',body->>'links_hash',body->>'answer_hash',body->>'disposition',body->>'reason',body->>'predecessor_review_id') THEN RAISE EXCEPTION 'BA review content mismatch' USING ERRCODE='23514'; END IF;
  IF NEW.kind='review.revoke' THEN
   SELECT * INTO v FROM ba_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.predecessor_review_id;
   IF v.kind<>'review.confirm' OR (v.request_version_id,v.answer_version_id) IS DISTINCT FROM (NEW.request_version_id,NEW.answer_version_id) THEN RAISE EXCEPTION 'BA review predecessor boundary' USING ERRCODE='23503'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='ba_events' THEN
  SELECT * INTO q FROM ba_request_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.request_version_id;
  IF NEW.answer_version_id IS NOT NULL THEN
   SELECT * INTO a FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.answer_version_id;
   IF a.request_version_id<>NEW.request_version_id THEN RAISE EXCEPTION 'BA event answer boundary' USING ERRCODE='23503'; END IF;
  END IF;
  IF NEW.review_id IS NOT NULL THEN
   SELECT * INTO v FROM ba_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.review_id;
   IF (v.request_version_id,v.answer_version_id) IS DISTINCT FROM (NEW.request_version_id,NEW.answer_version_id) THEN RAISE EXCEPTION 'BA event review boundary' USING ERRCODE='23503'; END IF;
  END IF;
  IF NEW.kind<>r.operation OR (NEW.kind='review.confirm' AND (NEW.state<>'resolved' OR NEW.review_id IS NULL)) OR (NEW.kind IN ('answer.record','answer.correct','review.revoke') AND NEW.state<>'answered') OR (NEW.kind IN ('request.open','request.revise','request.reopen','answer.retract','review.reject') AND NEW.state<>'open') OR (NEW.kind='request.withdraw' AND NEW.state<>'withdrawn') THEN RAISE EXCEPTION 'BA event type/state mismatch' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='ba_request_bindings' THEN
  IF r.input_hash<>NEW.input_hash THEN RAISE EXCEPTION 'BA binding hash mismatch' USING ERRCODE='23514'; END IF;
  allowed=CASE WHEN NEW.operation='party.register' THEN ARRAY['receipt_id','party_version_id'] WHEN NEW.operation='request.open' OR NEW.operation IN ('request.revise','request.reopen') THEN ARRAY['receipt_id','clarification_id','request_version_id','event_id'] WHEN NEW.operation IN ('answer.record','answer.correct','answer.retract') THEN ARRAY['receipt_id','clarification_id','answer_version_id','event_id'] WHEN NEW.operation IN ('review.confirm','review.reject','review.revoke') THEN ARRAY['receipt_id','clarification_id','review_id','event_id'] ELSE ARRAY['receipt_id','clarification_id','event_id'] END;
  SELECT array_agg(k ORDER BY k) INTO actual FROM jsonb_each(to_jsonb(NEW)) j(k,val) WHERE k=ANY(ARRAY['receipt_id','party_version_id','clarification_id','request_version_id','answer_version_id','review_id','event_id']) AND val<>'null'::jsonb;
  SELECT array_agg(k ORDER BY k) INTO allowed FROM unnest(allowed) k;
  IF actual IS DISTINCT FROM allowed THEN RAISE EXCEPTION 'BA binding output type' USING ERRCODE='23514'; END IF;
  IF NEW.party_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM ba_source_party_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.party_version_id AND receipt_id=NEW.receipt_id) THEN RAISE EXCEPTION 'BA binding party receipt' USING ERRCODE='23503'; END IF;
  IF NEW.event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM ba_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.event_id AND receipt_id=NEW.receipt_id AND clarification_id=NEW.clarification_id) THEN RAISE EXCEPTION 'BA binding event receipt' USING ERRCODE='23503'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (array_keys($tables) as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            DB::statement("CREATE TRIGGER ba_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            DB::statement("CREATE TRIGGER ba_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation()");
            if (in_array($table, ['ba_source_heads', 'ba_clarifications'], true)) {
                DB::statement("CREATE TRIGGER ba_projection_guard BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION guard_ba_projection()");
            } else {
                DB::statement("REVOKE UPDATE ON $table FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER ba_no_update BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
            if ($table !== 'ba_source_heads') {
                DB::statement("CREATE TRIGGER ba_fact_guard BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION guard_ba_fact()");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Immutable BA history must be preserved.');
    }
};
