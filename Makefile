SHELL := /usr/bin/env bash
ROOT_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))

.PHONY: deps bedrock-test deploy-lightsail publish dev

deps:
	$(ROOT_DIR)scripts/check-deps.sh

bedrock-test: deps
	$(ROOT_DIR)scripts/bedrock-smoke-test.sh

deploy-lightsail: deps
	$(ROOT_DIR)scripts/lightsail-deploy.sh deploy

publish:
	$(ROOT_DIR)scripts/git-publish.sh "$(MSG)"

dev:
	php -S localhost:8000 -t public
