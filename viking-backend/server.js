const express = require('express');
const cors = require('cors');
const axios = require('axios');
const nodemailer = require('nodemailer');

const app = express();
app.use(cors());
app.use(express.json());

// --- إعداداتك الخاصة ---
const TELEGRAM_TOKEN = '8877801901:AAFOSOg8kUekDn8H3rGXkWT0QH6RW4yfHXw';
const TELEGRAM_CHAT_ID = '8146084378';
const EMAIL_RECEIVER = 'cd.iraq.agency@gmail.com';

// إعداد مرسل الإيميل (يفضل استخدام App Password من حساب الجيميل مالتك)
// ملاحظة: استبدل YOUR_GMAIL و YOUR_APP_PASSWORD ببياناتك إذا أردت تفعيل الإيميل من سيرفرك
const transporter = nodemailer.createTransport({
    service: 'gmail',
    auth: {
        user: 'cd.iraq.agency@gmail.com',
        pass: 'YOUR_APP_PASSWORD_HERE' // يجب استخراج App Password من اعدادات الأمان بالجيميل
    }
});

app.post('/api/order', async (req, res) => {
    try {
        const { orderText, branchName, total } = req.body;

        // 1. إرسال إلى تليكرام
        const tgUrl = `https://api.telegram.org/bot${TELEGRAM_TOKEN}/sendMessage`;
        await axios.post(tgUrl, {
            chat_id: TELEGRAM_CHAT_ID,
            text: orderText,
            parse_mode: 'Markdown'
        });

        // 2. إرسال إلى الإيميل
        const mailOptions = {
            from: 'Viking Burger System <cd.iraq.agency@gmail.com>',
            to: EMAIL_RECEIVER,
            subject: `🚨 طلب جديد - فرع ${branchName} | ${total} IQD`,
            text: orderText.replace(/\*/g, '') // إزالة نجوم الماركدوان من الإيميل
        };
        
        // إذا حطيت باسوورد الجيميل فوك، شيل التعليق (//) عن السطر الجوة حتى يرسل إيميل
        // await transporter.sendMail(mailOptions);

        res.status(200).json({ success: true, message: 'تم إرسال الطلب بنجاح' });
    } catch (error) {
        console.error('Error processing order:', error.message);
        res.status(500).json({ success: false, error: 'Internal Server Error' });
    }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Backend running on port ${PORT}`));