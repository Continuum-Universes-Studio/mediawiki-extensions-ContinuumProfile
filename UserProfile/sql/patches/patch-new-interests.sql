-- patch-add-up-universes-pets-hobbies.sql
ALTER TABLE user_profile
ADD COLUMN up_universes VARCHAR(255) DEFAULT NULL,
ADD COLUMN up_pets VARCHAR(255) DEFAULT NULL,
ADD COLUMN up_hobbies VARCHAR(255) DEFAULT NULL,
ADD COLUMN up_heroes VARCHAR(255) DEFAULT NULL,
ADD COLUMN up_quote text;