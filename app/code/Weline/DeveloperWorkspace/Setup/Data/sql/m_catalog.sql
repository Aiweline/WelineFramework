/*
 Navicat Premium Data Transfer

 Source Server         : 本地宝塔
 Source Server Type    : MySQL
 Source Server Version : 80025
 Source Host           : localhost:3306
 Source Schema         : weline

 Target Server Type    : MySQL
 Target Server Version : 80025
 File Encoding         : 65001

 Date: 01/09/2022 23:16:49
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for w_document_catalog
-- ----------------------------
DROP TABLE IF EXISTS `w_document_catalog`;
CREATE TABLE `w_document_catalog`  (
  `id` smallint(0) NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '目录名',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '简介',
  `level` int(0) NOT NULL DEFAULT 0 COMMENT '目录层级',
  `icon` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'icon 图标',
  `selectedIcon` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'icon 选中图标',
  `color` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '颜色',
  `backColor` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '背景色',
  `position` int(0) NULL DEFAULT 0 COMMENT '排序',
  `is_active` smallint(0) NULL DEFAULT 0 COMMENT '是否激活',
  `pid` smallint(0) NULL DEFAULT NULL COMMENT '父目录',
  `create_time` timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP(0) COMMENT '创建时间',
  `update_time` timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP(0) ON UPDATE CURRENT_TIMESTAMP(0) COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `name`(`name`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 14 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '目录' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of w_document_catalog
-- ----------------------------
INSERT INTO `m_document_catalog` VALUES (1, '前言', '说在前面', 1, NULL, NULL, NULL, NULL, 0, 1, 0, '2022-04-25 19:54:36', '2022-04-26 21:54:46');
INSERT INTO `m_document_catalog` VALUES (2, '安装', '安装文档', 1, NULL, NULL, NULL, NULL, 0, 1, 0, '2022-04-25 20:26:03', '2022-04-25 20:40:18');
INSERT INTO `m_document_catalog` VALUES (6, '快速开始', '框架模组', 1, NULL, NULL, NULL, NULL, 0, 1, 0, '2022-04-28 21:36:36', '2022-04-30 19:55:25');
INSERT INTO `m_document_catalog` VALUES (8, 'Model', '模型文档', 2, NULL, NULL, NULL, NULL, 0, 0, 6, '2022-05-08 17:30:34', '2022-05-08 17:30:34');
INSERT INTO `m_document_catalog` VALUES (9, 'Controller', '使用控制器', 2, NULL, NULL, NULL, NULL, 0, 1, 6, '2022-05-08 17:48:27', '2022-05-08 17:48:47');
INSERT INTO `m_document_catalog` VALUES (10, '框架规范', '框架类的规范', 1, NULL, NULL, NULL, NULL, 0, 1, 0, '2022-05-08 17:55:12', '2022-05-08 17:55:54');
INSERT INTO `m_document_catalog` VALUES (11, 'Event', '模组事件', 2, NULL, NULL, NULL, NULL, 0, 1, 6, '2022-05-09 21:52:58', '2022-05-09 21:53:15');
INSERT INTO `m_document_catalog` VALUES (12, 'Plugin', 'WelineFramework插件功能', 2, NULL, NULL, NULL, NULL, 0, 1, 6, '2022-05-09 21:56:45',
                                         '2022-05-09 21:59:14');

SET FOREIGN_KEY_CHECKS = 1;
