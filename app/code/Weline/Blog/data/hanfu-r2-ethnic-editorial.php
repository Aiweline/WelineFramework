<?php
declare(strict_types=1);

/**
 * Evidence-led bilingual copy for the 56 ethnic-dress article pairs.
 *
 * @param array<string,string> $profile
 * @return array{lede:string,sections:list<array{heading:string,paragraphs:list<string>}>,reviewed_facts:list<string>}
 */
function hanfuR2EthnicEditorial(array $profile, string $variant, string $title, string $locale): array
{
    if (!in_array($variant, ['overview', 'occasion'], true)) {
        throw new InvalidArgumentException('Variant must be overview or occasion.');
    }

    $en = str_starts_with(strtolower($locale), 'en');
    $suffix = $en ? '_en' : '';
    $facts = [
        'code' => trim((string)($profile['code'] ?? '')),
        'name' => trim((string)($profile[$en ? 'en' : 'zh'] ?? '')),
        'region' => trim((string)($profile['region' . $suffix] ?? '')),
        'silhouette' => trim((string)($profile['silhouette' . $suffix] ?? '')),
        'fabric' => trim((string)($profile['fabric' . $suffix] ?? '')),
        'occasion' => trim((string)($profile['occasion' . $suffix] ?? '')),
        'motif' => trim((string)($profile['motif' . $suffix] ?? '')),
        'title' => trim($title),
    ];
    foreach ($facts as $key => $value) {
        if ($value === '') {
            throw new InvalidArgumentException('Missing ethnic editorial fact: ' . $key);
        }
    }

    $ordinal = hanfuR2EthnicOrdinal($facts['code']);
    $headings = hanfuR2EthnicHeadings($variant, $en, $ordinal);

    return $en
        ? hanfuR2EthnicEnglish($facts, $variant, $headings)
        : hanfuR2EthnicChinese($facts, $variant, $headings);
}

function hanfuR2EthnicOrdinal(string $code): int
{
    static $codes = [
        'han', 'mongol', 'hui', 'tibetan', 'uyghur', 'miao', 'yi', 'zhuang',
        'buyei', 'korean', 'manchu', 'dong', 'yao', 'bai', 'tujia', 'hani',
        'kazakh', 'dai', 'li', 'lisu', 'wa', 'she', 'gaoshan', 'lahu',
        'shui', 'dongxiang', 'naxi', 'jingpo', 'kirgiz', 'tu', 'daur', 'mulao',
        'qiang', 'blang', 'salar', 'maonan', 'gelao', 'xibe', 'achang', 'pumi',
        'tajik', 'nu', 'uzbek', 'russian', 'ewenki', 'deang', 'bonan', 'yugur',
        'gin', 'tatar', 'derung', 'oroqen', 'hezhen', 'monba', 'lhoba', 'jino',
    ];
    $ordinal = array_search($code, $codes, true);
    return $ordinal === false ? abs((int)crc32($code)) : $ordinal;
}

/** @param list<string> $choices */
function hanfuR2EthnicPick(array $choices, string $code, string $salt): string
{
    $value = hexdec(substr(hash('sha256', $code . '|' . $salt), 0, 7));
    return $choices[(int)($value % count($choices))];
}

/** @return list<string> */
function hanfuR2EthnicHeadings(string $variant, bool $en, int $ordinal): array
{
    $banks = $en
        ? [
            'overview' => [
                ['Place before costume labels', 'Start with the regional record', 'Locate the tradition before naming it', 'A geographic claim needs a date'],
                ['Read construction, not colour', 'Silhouette begins with garment relations', 'Collar, layer and fastening are the first clues', 'Test the outline piece by piece'],
                ['Reconstruct the dressing sequence', 'Layers explain the finished outline', 'How the pieces work together', 'From inner layer to visible finish'],
                ['Separate fibre, surface and technique', 'Material evidence is more than a fabric name', 'Read weave and embroidery as different records', 'What the textile can actually prove'],
                ['Motif is evidence only in context', 'Pattern, placement and local meaning', 'Do not identify a community from one symbol', 'Read ornament without turning it into a logo'],
                ['Variation is part of the tradition', 'One category contains many local wardrobes', 'Gender, age and locality change the result', 'Avoid the single-costume shortcut'],
                ['A verification and care checklist', 'What to record before publishing', 'Turn visual clues into evidence', 'Responsible display, purchase and storage'],
            ],
            'occasion' => [
                ['Name the event before judging the clothes', 'Occasion is a social setting, not a backdrop', 'Begin with who is gathering and why', 'Read festival dress as participation'],
                ['Preparation creates the final silhouette', 'Dressing order reveals function', 'Layering before ornament', 'How the outfit is assembled for use'],
                ['Movement tests every garment choice', 'Watch how dress behaves in action', 'Gesture, weather and duration matter', 'Useful clues appear while people move'],
                ['Role changes the acceptable ensemble', 'Age, gender and family position shape dress', 'Local protocol comes before spectacle', 'Participants do not dress alike'],
                ['Craft becomes legible at the occasion', 'Material, light and distance alter what we see', 'Pattern placement works with movement', 'Technique becomes visible in use'],
                ['Continuity does not mean immobility', 'Contemporary participation and historical limits', 'Living dress can change with context', 'Separate revival, performance and daily practice'],
                ['Document the event without flattening it', 'A field-note and care checklist', 'Caption, consent, storage and follow-up', 'How to publish the image responsibly'],
            ],
        ]
        : [
            'overview' => [
                ['先定地域，再谈服饰名称', '从地域记录开始核对', '先定位传统，再辨认名称', '地域判断必须带上时间'],
                ['不靠颜色，先看结构', '轮廓来自衣片之间的关系', '领型、层次与系结是第一线索', '把整体轮廓逐件拆开'],
                ['按穿着次序复原结构', '层次关系决定最终轮廓', '各件服装怎样共同工作', '从内层到外观收束'],
                ['把纤维、表面与工艺分开', '材料证据不只是一个面料名', '织造与刺绣是两类记录', '织物究竟能证明什么'],
                ['纹样必须放回语境中阅读', '图案、位置与地方含义', '不能凭一个符号判断族属', '不要把装饰当成族群标志'],
                ['差异本身就是传统的一部分', '一个族称包含多套地方衣橱', '性别、年龄与地域都会改变着装', '避开“一族一套服装”的捷径'],
                ['核查、选购与养护清单', '购买或发布前要记录什么', '把视觉线索变成可核查证据', '负责任的展示、购买与收纳'],
            ],
            'occasion' => [
                ['先说明场合，再判断着装', '场合是社会关系，不是摄影背景', '先问谁为何相聚', '把节庆服饰理解为参与行为'],
                ['准备过程塑造最终轮廓', '穿着次序说明功能', '先分层，再谈装饰', '一套礼俗着装怎样组合'],
                ['动作会检验每一件衣物', '观察服饰在行动中的状态', '姿态、天气与时长都重要', '人在移动时，线索才会显现'],
                ['角色不同，合适的组合也不同', '年龄、性别与家庭位置影响着装', '地方礼序先于视觉奇观', '参与者不会穿成同一种样子'],
                ['工艺在场合中变得可读', '材质、光线与观看距离共同作用', '纹样位置会配合动作', '使用状态更能说明工艺'],
                ['延续不等于一成不变', '当代参与与历史边界', '活态服饰会变化但不能脱离语境', '区分复兴、表演与日常实践'],
                ['不要用一张照片压扁整个场合', '田野记录与养护清单', '说明文字、同意、收纳与复核', '怎样负责任地发布场合照片'],
            ],
        ];

    $topic = $banks[$variant];
    $first = $ordinal % 4;
    $second = intdiv($ordinal, 4) % 4;
    $result = [];
    foreach ($topic as $index => $choices) {
        $choice = $index === 0 ? $first : ($index === 1 ? $second : ($ordinal + $index * 3 + intdiv($ordinal, 4)) % 4);
        $result[] = ($index + 1) . '. ' . $choices[$choice];
    }
    return $result;
}

/**
 * @param array{code:string,name:string,region:string,silhouette:string,fabric:string,occasion:string,motif:string,title:string} $f
 * @param list<string> $h
 * @return array{lede:string,sections:list<array{heading:string,paragraphs:list<string>}>,reviewed_facts:list<string>}
 */
function hanfuR2EthnicEnglish(array $f, string $variant, array $h): array
{
    if ($variant === 'overview') {
        $lede = $f['title'] . ' examines ' . $f['name'] . ' dress through evidence associated with ' . $f['region']
            . '. A reliable identification starts with construction and provenance rather than colour or a generic “ethnic” label. The working clues are '
            . $f['silhouette'] . '; ' . $f['fabric'] . '; and ' . $f['motif'] . '.';
        $paragraphs = [
            [
                $f['name'] . ' is an umbrella identity, not one timeless uniform. The geographic anchor here is ' . $f['region']
                . ', yet county, village, migration, livelihood, faith, age and gender can all change a wardrobe. Record locality, approximate date, wearer or maker, event and source before generalising from an image.',
                hanfuR2EthnicPick([
                    'When those fields are absent, describe only visible structure and mark the attribution as provisional.',
                    'When sources disagree, keep both local names and explain their dates instead of forcing a false consensus.',
                    'Museum records, community accounts and dated field photographs are stronger together than an anonymous sales caption.',
                    'Administrative ethnicity and clothing tradition overlap but are not interchangeable; local self-description deserves its own field.',
                ], $f['code'], 'en-overview-1'),
            ],
            [
                'The primary silhouette note is: ' . $f['silhouette'] . '. Check collar direction, opening, sleeve attachment, length, waist control, upper-to-lower proportion, headwear and footwear in that order. These relations distinguish an ensemble more reliably than decorative colour.',
                'Front, side and back views are required when pleats, wraps or fastening points carry the identification. A cropped portrait may show embroidery while hiding the construction, and accessories cannot repair a structurally wrong base outfit.',
            ],
            [
                'Read the clothes as a sequence: inner layer, main upper and lower garments, fastening or sash, outer protection, then headwear, footwear and portable ornament. The order should remain compatible with ' . $f['silhouette'] . ' even when season, wealth and personal preference change.',
                'This method exposes a plausible textile paired with an unrelated collar, ceremonial headwear put on daily separates, or a modern one-piece dress used to imitate several historical layers. Editorial reconstruction must always be labelled as such.',
            ],
            [
                'The material record is ' . $f['fabric'] . '. Separate fibre from weave and both from embroidery, dye, print or appliqué. A photograph that looks like silk or brocade cannot prove fibre, handwork or date; the reverse, selvedge, stitch path and an attributable catalogue provide stronger evidence.',
                hanfuR2EthnicPick([
                    'Support heavy ornament from below, isolate metal from damp textile and test loose dye at a hidden edge before cleaning.',
                    'Avoid crushing folds and prolonged display light; actual fibre labels override assumptions based on visual style.',
                    'Before buying, request seam, reverse-embroidery, closure and full front-and-back photographs instead of relying on a hero crop.',
                    'A reproduction should disclose substituted fibres and machine embroidery rather than advertising undocumented handwork.',
                ], $f['code'], 'en-overview-4'),
            ],
            [
                'The recorded motif vocabulary is ' . $f['motif'] . '. Meaning depends on placement, technique, local terminology and date. Similar diamonds, clouds, flowers and animals appear across communities, so motif alone is never an ethnicity detector. Identify whether it is woven, stitched, applied or printed and where it sits on the garment.',
                'A precise caption separates what is visible from an interpretation attributed to a named community or collection source. Sacred, ancestral or protective imagery should not be turned into generic decoration for an unrelated product.',
            ],
            [
                'The documented use contexts include ' . $f['occasion'] . '. Occasion wear can be newer, brighter, borrowed, inherited or assembled differently from daily wear; tourism and stage teams may standardise it further. Date and label those living forms instead of projecting every modern ensemble backward.',
                'Map variation rather than editing it out. Compare like occasion with like occasion, retain local terms and state who wears each form. Differences between photographs may reflect season or life stage rather than error.',
            ],
            [
                'Before publishing or purchasing, compare the complete outfit with at least two attributable records and retain source, creator, date, licence and crop note. The article image is an editorial orientation, not proof that every ' . $f['name'] . ' person dresses this way.',
                'Keep this evidence card with the asset: region—' . $f['region'] . '; structure—' . $f['silhouette'] . '; material—' . $f['fabric'] . '; contexts—' . $f['occasion'] . '; motifs—' . $f['motif'] . '. File-manager alt text should describe visible clothing without inventing village, dynasty or ritual rank.',
            ],
        ];
    } else {
        $lede = $f['title'] . ' treats ' . $f['occasion'] . ' as a setting in which ' . $f['name'] . ' dress is prepared, worn and understood in '
            . $f['region'] . '. The focus is social use, not the false idea that one festival portrait can define a whole community.';
        $paragraphs = [
            [
                'The documented occasion range is ' . $f['occasion'] . '. Identify the event, locality, year and participant role first: host and guest, elder and young adult, performer and audience may follow different expectations. Religious observance, wedding protocol, seasonal work and staged display must not collapse into one “festival costume” label.',
                hanfuR2EthnicPick([
                    'Ask what the clothing enables—warmth, motion, modesty, visibility, rank or exchange—before interpreting decoration.',
                    'Record whether the outfit is owned, inherited, borrowed, hired or newly made for a performance team.',
                    'The same named festival can differ between counties, which is why date and place belong in the first caption sentence.',
                    'Keep a participant’s local garment term and translate its function instead of replacing it with a broad costume label.',
                ], $f['code'], 'en-occasion-1'),
            ],
            [
                'Preparation begins with the structural record: ' . $f['silhouette'] . '. Lay out inner layers, main garments, sash or closure, outer protection, headwear, footwear and ornaments. Confirm which pieces form one local set and which vary with weather, age, marital status, family role or stage of the ceremony.',
                'Photographing the dressing sequence reveals hidden ties, wrap direction, pleat control and weight distribution. It also prevents a visually dominant accessory from being paired with an unrelated base garment.',
            ],
            [
                'Movement tests the choices. Walking, singing, dancing, greeting, riding or serving food changes how hems clear the ground, belts carry weight and ornaments sound or catch light. An occasion image should show meaningful action without cropping away the fastening, lower garment or footwear.',
                'A static portrait and an action frame answer different questions. Do not duplicate one subject and crop it twice as if it were two sources; weather and the removal of an outer layer must be recorded as part of the sequence.',
            ],
            [
                'Participants need not look identical. Across ' . $f['region'] . ', age, gender, faith, marital status, household means, local taste and ritual task can alter colour, headwear, ornament weight and formality. Explain only differences supported by evidence.',
                'If a source omits the wearer’s role, use observable language rather than assigning bridal, priestly or ancestral meaning. Precision is more respectful than a dramatic but unverified caption.',
            ],
            [
                'The material record—' . $f['fabric'] . '—and motif vocabulary—' . $f['motif'] . '—read differently in motion, shade and distance. Wide views explain silhouette, medium views show layers, and macro views document stitch or weave. They should be independent frames with clear captions, never a diptych repeating the same person and outfit.',
                'Natural wear, repairs and replacements are evidence of use. Keep colour credible to the photographed light and attribute motif interpretations to a community source or collection record.',
            ],
            [
                'Living tradition can include new cloth, machine stitching, revived patterns, stage conventions and personal styling. These changes are not automatically inauthentic, but they need dates and contexts. Separate inherited practice, community-led revival, tourism presentation and commercial costume.',
                'Contemporary participation follows organiser or community guidance on modesty, headwear, sacred motifs and photography. Buying a similar outfit does not confer ritual status.',
            ],
            [
                'A publishable event record retains source URL or collection number, creator, date, locality, licence, consent where relevant and crop/edit note. The illustration for this article is editorial and must not be cited as field evidence.',
                'Care follows actual material, not ethnicity: air wool and fur away from heat, test indigo and embroidery for transfer, prevent heavy metal from stretching cloth and support headwear in storage. Reference: ' . $f['region'] . '; ' . $f['silhouette'] . '; ' . $f['fabric'] . '; ' . $f['occasion'] . '; ' . $f['motif'] . '.',
            ],
        ];
    }

    $sections = [];
    foreach ($h as $index => $heading) {
        $sections[] = ['heading' => $heading, 'paragraphs' => $paragraphs[$index]];
    }
    return [
        'lede' => $lede,
        'sections' => $sections,
        'reviewed_facts' => [
            'Regional reference: ' . $f['region'],
            'Garment-system clue: ' . $f['silhouette'],
            'Material record: ' . $f['fabric'],
            'Documented contexts: ' . $f['occasion'],
            'Motif vocabulary: ' . $f['motif'],
        ],
    ];
}

/**
 * @param array{code:string,name:string,region:string,silhouette:string,fabric:string,occasion:string,motif:string,title:string} $f
 * @param list<string> $h
 * @return array{lede:string,sections:list<array{heading:string,paragraphs:list<string>}>,reviewed_facts:list<string>}
 */
function hanfuR2EthnicChinese(array $f, string $variant, array $h): array
{
    if ($variant === 'overview') {
        $lede = '《' . $f['title'] . '》以' . $f['region'] . '为地域锚点，核对' . $f['name'] . '服饰的形制与材料。可靠辨认从结构与出处开始，而不是醒目颜色或笼统的“民族风”标签开始。工作线索是“'
            . $f['silhouette'] . '”“' . $f['fabric'] . '”以及“' . $f['motif'] . '”。';
        $paragraphs = [
            [
                $f['name'] . '是范围很大的族群称谓，不是一套永远不变的制服。本文参照' . $f['region'] . '，但县域、村寨、迁徙经历、生计、信仰、年龄与性别都可能改变衣橱。专业图录应先记录地方、年代、穿着者或制作者、场合和资料来源，再从个案作概括。',
                hanfuR2EthnicPick([
                    '字段不全时，只描述画面中能看到的结构，并把族属或支系判断明确标为待核。',
                    '资料说法不同时，应保留各自地方名称并说明年代，而不是强行拼成一个答案。',
                    '博物馆藏品、社区口述与有日期的田野照片互相印证，比匿名商品图标题可靠。',
                    '行政民族识别与地方服饰传统有交集，却不能互相替代；地方自称应单独记录。',
                ], $f['code'], 'zh-overview-1'),
            ],
            [
                '首要形制记录是：' . $f['silhouette'] . '。核对时依次看领襟方向、开合、袖身连接、衣长、腰部控制、上下装比例、头饰与鞋履。这些关系比颜色更能区分完整地方组合与临时拼接的影楼服。',
                '只要褶裥、围裹或系结承担辨认功能，就要保留正面、侧面与背面。半身肖像可能展示刺绣却裁掉结构，银饰、帽子和腰带也不能替错误的基础衣片补出族属。',
            ],
            [
                '按顺序拆解整套服装：内层、主要上装与下装、系带或腰带、防护或礼仪外层，最后才是头饰、鞋履和可移动饰物。即使颜色、季节与家庭条件不同，这个次序仍应与“' . $f['silhouette'] . '”相容。',
                '顺序还能暴露错配：看似合适的织物配了无关领型、礼仪头饰套在日常分体衣上，或用现代连衣裙模拟多层结构。编辑性复原图必须与有出处的实物或田野照片分开标注。',
            ],
            [
                '材料记录是：' . $f['fabric'] . '。纤维、织物组织与刺绣、印染、贴布等表面工艺要分别判断。照片看起来像丝或锦，不能自动证明纤维、手工与年代；背面、布边、针路和可署名图录更可靠。',
                hanfuR2EthnicPick([
                    '操作时从下方承托重饰，把金属与潮湿织物隔开；清洁前在隐蔽布边测试浮色。',
                    '收纳时避免压出死褶和长期照明；实际纤维标识永远优先于“民族风”猜测。',
                    '选购应索取接缝、绣背、扣结及完整正反面近照，而不是只看高饱和度主图。',
                    '复制品应说明替代纤维和机绣工艺，外观相似不能支持无证据的“纯手工”宣传。',
                ], $f['code'], 'zh-overview-4'),
            ],
            [
                '当前纹样记录是：' . $f['motif'] . '。含义取决于落位、制作方法、地方称呼与年代。菱形、云纹、花卉和动物形在许多社区都会出现，所以单凭图案不能可靠判断族属；还要说明它是织、绣、贴还是印，以及落在哪个衣片部位。',
                '严谨图注先说可见事实，再把有出处的社区解释或收藏记录单独列出。涉及神圣、祖先或护佑含义的图形，不应被移植为与原语境无关的通用商品装饰。',
            ],
            [
                '有记录的使用场合包括：' . $f['occasion'] . '。场合服可能比日常服更新、更鲜艳，也可能借用、继承或改变组合顺序；舞台与旅游展示还会标准化。它们属于活态历史，但必须写明年代和用途，不能把现代组合都倒推成古老定制。',
                '差异需要记录而不是修掉：保留地方名称，说明谁在何种场合穿，并只比较同类场合。两张照片的不同可能来自季节或人生阶段，而不是谁“穿错了”。',
            ],
            [
                '发布或购买前，至少用两份可署名资料核对整套形制，并随图片保存来源、作者、日期、授权与裁切说明。文章配图只承担导读作用，不能证明每一位' . $f['name'] . '成员都如此穿着。',
                '证据卡包括：地域—' . $f['region'] . '；结构—' . $f['silhouette'] . '；材料—' . $f['fabric'] . '；场合—' . $f['occasion'] . '；纹样—' . $f['motif'] . '。文件管理器替代文本只描述可见衣物，不擅自写入村寨、朝代或礼仪身份。',
            ],
        ];
    } else {
        $lede = '《' . $f['title'] . '》把' . $f['occasion'] . '放回' . $f['region'] . '的社会环境，观察' . $f['name'] . '服饰怎样准备、穿着、行动并被理解。重点是场合中的社会使用，而不是让一张节庆照片代表整个族群。';
        $paragraphs = [
            [
                '当前记录的场合范围是：' . $f['occasion'] . '。先确认活动、地点、年份与参与者角色；主客、长幼、表演者与普通参与者可能遵循不同要求。宗教礼仪、婚礼程序、季节劳动和公共舞台展示不能压缩成“节庆盛装”一个类别。',
                hanfuR2EthnicPick([
                    '解释装饰前，先问衣物要解决保暖、行动、端庄、角色辨认还是馈赠交换。',
                    '田野笔记应说明服装是自有、继承、借用、租用，还是为表演队新制。',
                    '同名节日在不同县域也可能不同，所以日期和地点应进入图注第一句。',
                    '参与者使用地方词称呼衣物时，应保留原词并解释功能，不一律改成“民族服装”。',
                ], $f['code'], 'zh-occasion-1'),
            ],
            [
                '准备过程先回到结构记录：' . $f['silhouette'] . '。依次摆出内层、主衣、系带或扣结、外层、头饰、鞋履与饰物，确认哪些属于同一地方组合，哪些会因天气、年龄、婚姻状态、家庭角色或礼仪进程增减。',
                '穿着次序比一张正面定妆照更能说明隐藏系带、围裹方向、褶裥控制与重量分配，也能避免把视觉最强的配饰错误套在无关基础衣物上。',
            ],
            [
                '动作是实际检验。行走、歌舞、问候、骑乘或端送食物，会改变裙摆离地、腰带受力和饰物发声、反光的方式。' . $f['name'] . '场合图应展示可理解的动作，同时保留扣结、下装与鞋履。',
                '静态肖像与行动照片回答不同问题；不能把同一人物同一套衣服裁两次冒充两份素材。天气、活动时长与中途脱去外层也要写进记录。',
            ],
            [
                '参与者不会也不应完全一样。在' . $f['region'] . '内部，年龄、性别、信仰、婚姻状态、家庭条件、地方审美与礼仪分工都会影响颜色、头饰、饰物重量和正式程度。只解释证据能支持的差别。',
                '资料未说明身份时，就使用可观察语言，而不是擅自指认新娘、祭司或祖先象征。准确的克制比戏剧化但无依据的图注更尊重穿着者。',
            ],
            [
                '“' . $f['fabric'] . '”的材料记录与“' . $f['motif'] . '”的纹样范围，会随动作、阴影和距离产生不同可读性。远景说明轮廓，中景说明层次，微距记录针法和织纹；三者应是独立画面，不能继续用同一人物同一服装拼成双联图。',
                '磨损、修补和替换件都是使用证据，不应为“高级感”全部修掉。色彩保持与现场光线一致，纹样解释则署名社区资料或收藏记录。',
            ],
            [
                '活态传统本来就包含新布料、机缝、复兴纹样、舞台规则和个人搭配。这些变化不必自动判为“不正宗”，但要交代年代与语境，并区分有记录的延续、社区主导复兴、旅游展示与商业演出服。',
                '当代参与遵守组织者或社区对端庄、头饰、神圣纹样与摄影的要求。购买相似服装不会赋予礼仪身份，搭配建议也不鼓励模仿受限角色。',
            ],
            [
                '可发布的场合记录保存来源网址或藏品编号、摄影者或制作者、日期、地点、授权、必要的同意和裁切说明。本文图片属于编辑性说明图，不可引用为田野证据。',
                '养护按真实材料处理：毛与皮避开直接热源，靛蓝与绣线先测浮色，重金属避免牵拉织物，头饰用支撑保持形状。参考卡：' . $f['region'] . '；' . $f['silhouette'] . '；' . $f['fabric'] . '；' . $f['occasion'] . '；' . $f['motif'] . '。',
            ],
        ];
    }

    $sections = [];
    foreach ($h as $index => $heading) {
        $sections[] = ['heading' => $heading, 'paragraphs' => $paragraphs[$index]];
    }
    return [
        'lede' => $lede,
        'sections' => $sections,
        'reviewed_facts' => [
            '地域参照：' . $f['region'],
            '形制线索：' . $f['silhouette'],
            '材料记录：' . $f['fabric'],
            '已知场合：' . $f['occasion'],
            '纹样范围：' . $f['motif'],
        ],
    ];
}
